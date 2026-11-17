// Envoi des photos par lots (voir src/Service/PhotoBatchUpload.php).
//
// Le serveur n'accepte qu'un nombre limité de fichiers par envoi (max_file_uploads, 20 par
// défaut chez PHP) et un poids total limité (post_max_size). Au-delà, PHP ne prévient pas : il
// ignore les photos en trop (« je ne peux mettre que 20 photos par article ») ou jette tout le
// formulaire.
//
// Quand la sélection dépasse ces limites, ce script retient l'enregistrement, envoie les photos
// par lots, ajoute au formulaire le nom de chaque photo déposée, puis enregistre le formulaire
// normalement (avec le même bouton), sans les fichiers : le serveur rattache alors ces photos,
// dans l'ordre choisi. Une sélection qui tient en un seul envoi part comme avant.
//
// Les limites et l'adresse d'envoi sont posées par le serveur sur le champ (data-max-files…).
// Les photos ont déjà été réduites dans le navigateur par admin_image_resize.js (CarotteImages).
(function () {
  const SELECTOR = 'input[type="file"][data-photos-envoi-url]';
  const FIELD = "photos_envoyees";
  // Place gardée dans chaque envoi pour le reste du formulaire et l'enrobage de chaque fichier.
  const MARGIN_BYTES = 256 * 1024;

  let running = false;

  // En phase de capture : on passe avant les autres scripts (« Envoi en cours… », brouillon,
  // alerte de départ), qui reprendront la main au vrai enregistrement.
  document.addEventListener("submit", onSubmit, true);

  function onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const input = form.querySelector(SELECTOR);
    if (!input) return;

    if (running) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }

    const files = Array.from(input.files || []);
    const limits = readLimits(input);
    if (files.length === 0 || fitsInOneRequest(files, limits)) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    // Photos encore en cours de réduction : les boutons sont bloqués, on attend qu'elles soient prêtes.
    if (window.CarotteImages?.isBusy()) return;

    sendInBatches(form, input, files, limits, event.submitter);
  }

  function readLimits(input) {
    const read = (name) => Number.parseInt(input.dataset[name] || "0", 10) || 0;

    return {
      url: input.dataset.photosEnvoiUrl,
      token: input.dataset.photosEnvoiToken || "",
      maxFiles: read("maxFiles") || 20,
      maxBytes: read("maxBytes"),
      maxTotalBytes: read("maxTotalBytes"),
    };
  }

  function budget(limits) {
    return limits.maxTotalBytes > 0 ? limits.maxTotalBytes - MARGIN_BYTES : Infinity;
  }

  function tooHeavy(file, limits) {
    return (limits.maxBytes > 0 && file.size > limits.maxBytes) || file.size > budget(limits);
  }

  function fitsInOneRequest(files, limits) {
    const total = files.reduce((sum, file) => sum + file.size, 0);

    return files.length <= limits.maxFiles && total <= budget(limits) && !files.some((file) => tooHeavy(file, limits));
  }

  // Des lots d'au plus maxFiles photos et d'au plus le poids accepté, dans l'ordre de la sélection.
  function makeBatches(files, limits) {
    const batches = [];
    let current = [];
    let bytes = 0;

    files.forEach((file) => {
      if (current.length >= limits.maxFiles || bytes + file.size > budget(limits)) {
        batches.push(current);
        current = [];
        bytes = 0;
      }
      current.push(file);
      bytes += file.size;
    });
    if (current.length > 0) batches.push(current);

    return batches;
  }

  async function sendInBatches(form, input, files, limits, submitter) {
    running = true;
    window.CarotteImages?.blockSubmit(true);
    const buttons = saveButtonsOf(form);
    const status = statusFor(input);
    status.clear();

    const errors = files
      .filter((file) => tooHeavy(file, limits))
      .map((file) => `« ${file.name} » : trop lourde (${formatMo(limits.maxBytes || limits.maxTotalBytes)} au maximum par photo).`);
    let remaining = files.filter((file) => !tooHeavy(file, limits));
    const total = remaining.length;
    let sent = 0;
    let failure = null;

    for (const batch of makeBatches(remaining, limits)) {
      buttons.progress(`Envoi des photos : ${sent} / ${total}…`);
      status.info(`Envoi des photos : ${sent} sur ${total}…`);

      try {
        const result = await sendBatch(batch, limits);
        result.photos.forEach((photo) => addHiddenPhoto(form, input, photo));
        errors.push(...result.erreurs);
      } catch (error) {
        failure = error;
        break;
      }

      // Ce qui est parti ne repartira pas : en cas de problème, un nouveau clic reprend la suite.
      sent += batch.length;
      remaining = remaining.filter((file) => !batch.includes(file));
      setFiles(input, remaining);
    }

    running = false;
    window.CarotteImages?.blockSubmit(false);
    buttons.restore();

    if (failure) {
      const next =
        sent > 0
          ? `Les ${sent} premières photos sont bien arrivées. Clique à nouveau sur « Enregistrer » pour envoyer les ${remaining.length} autres.`
          : "Clique à nouveau sur « Enregistrer » pour réessayer.";
      status.error([failure.message, next]);
      return;
    }

    setFiles(input, []);

    if (errors.length > 0) {
      const ready = form.querySelectorAll(`input[type="hidden"][name^="${FIELD}["][name$="[fichier]"]`).length;
      status.error([
        "Certaines photos n'ont pas été ajoutées :",
        ...errors,
        ready > 0
          ? `Les ${ready} autres sont prêtes : clique à nouveau sur « Enregistrer » pour les ajouter à l'article.`
          : "Aucune photo n'a pu être ajoutée.",
      ]);
      return;
    }

    status.info(`${sent} photos envoyées. Enregistrement…`);
    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
  }

  async function sendBatch(batch, limits) {
    const data = new FormData();
    data.append("_token", limits.token);
    batch.forEach((file) => data.append("photos[]", file, file.name));

    let response;
    try {
      response = await fetch(limits.url, {
        method: "POST",
        body: data,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
    } catch {
      throw new Error("Impossible de joindre le serveur : vérifie la connexion internet.");
    }

    const isJson = (response.headers.get("Content-Type") || "").includes("json");
    const payload = isJson ? await response.json().catch(() => null) : null;
    if (!payload) {
      // Une page HTML au lieu d'une réponse : en général la page de connexion (session expirée).
      throw new Error("Ta session a peut-être expiré : reconnecte-toi dans un autre onglet, puis réessaie.");
    }
    if (!response.ok) {
      throw new Error(payload.message || "Le serveur n'a pas accepté l'envoi des photos.");
    }

    return { photos: payload.photos || [], erreurs: payload.erreurs || [] };
  }

  function addHiddenPhoto(form, input, photo) {
    const index = form.querySelectorAll(`input[type="hidden"][name^="${FIELD}["][name$="[fichier]"]`).length;

    [
      ["fichier", photo.fichier],
      ["legende", photo.legende],
    ].forEach(([key, value]) => {
      const hidden = document.createElement("input");
      hidden.type = "hidden";
      hidden.name = `${FIELD}[${index}][${key}]`;
      hidden.value = value;
      form.appendChild(hidden);
    });

    // Les photos sont parties : le champ, désormais vide, ne doit plus bloquer l'enregistrement
    // (il est obligatoire dans la médiathèque).
    input.required = false;
  }

  function setFiles(input, files) {
    const transfer = new DataTransfer();
    files.forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
  }

  // Les boutons d'enregistrement sont en haut de la page, loin du champ : c'est là qu'on montre
  // l'avancement. EasyAdmin 4 les sort du formulaire et les y rattache par l'attribut form="…".
  function saveButtonsOf(form) {
    const buttons = Array.from(document.querySelectorAll('button[type="submit"]')).filter((button) => button.form === form);
    const originals = buttons.map((button) => button.innerHTML);

    return {
      progress(text) {
        buttons.forEach((button) => {
          button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${text}`;
        });
      },
      restore() {
        buttons.forEach((button, index) => {
          button.innerHTML = originals[index];
        });
      },
    };
  }

  function statusFor(input) {
    let box = input.parentElement.querySelector(".photo-batches-status");
    if (!box) {
      box = document.createElement("div");
      box.className = "photo-batches-status form-text";
      input.parentElement.appendChild(box);
    }

    return {
      clear() {
        box.replaceChildren();
        box.classList.remove("text-danger");
        box.removeAttribute("role");
      },
      info(text) {
        box.classList.remove("text-danger");
        box.setAttribute("role", "status");
        box.textContent = text;
      },
      error(lines) {
        box.classList.add("text-danger");
        box.setAttribute("role", "alert");
        box.replaceChildren(
          ...lines.map((line) => {
            const p = document.createElement("p");
            p.className = "mb-1";
            p.textContent = line;
            return p;
          })
        );
        box.scrollIntoView({ block: "center", behavior: "smooth" });
      },
    };
  }

  function formatMo(bytes) {
    return `${(bytes / 1024 / 1024).toFixed(1).replace(".", ",").replace(/,0$/, "")} Mo`;
  }
})();
