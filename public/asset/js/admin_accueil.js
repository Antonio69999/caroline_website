/*
 * Admin « Page d'accueil » : choisir la partie de la photo à garder et voir le résultat.
 *
 * Pour l'ordinateur et pour le téléphone, ce script ajoute sous les boutons « Affichage » :
 *  - la photo entière avec un CADRE à déplacer (clic, glisser, ou flèches du clavier) : ce qui est
 *    dans le cadre est ce qui restera visible ; le curseur de zoom rétrécit le cadre ;
 *  - un APERÇU de la page, dessiné avec les mêmes règles CSS que le site (accueil.css).
 * Les vrais champs du formulaire (partie à garder X/Y, zoom) restent là : le script ne fait que
 * les remplir. Sans JavaScript, ils restent visibles et utilisables à la main.
 *
 * Le calcul du cadre vient des règles CSS du site : la photo remplit l'écran (object-fit: cover),
 * son point X %/Y % est aligné sur le point X %/Y % de l'écran, puis le zoom grossit autour de ce
 * point. Résultat : le cadre, mesuré en fraction de la photo, a pour taille
 *    largeur = (largeur d'écran / largeur de la photo affichée) / zoom   (idem en hauteur)
 * et se déplace de 0 à (1 - sa taille) quand X va de 0 % à 100 %. Donc X = position / (1 - taille).
 */
(function () {
  "use strict";

  // Forme de l'écran dessiné dans l'aperçu (largeur / hauteur) : un ordinateur type et un téléphone type.
  // À garder d'accord avec admin_accueil.css (.accueil-apercu--bureau / --mobile). Le calcul du cadre
  // mesure ensuite l'aperçu tel qu'il est affiché ; ces valeurs ne servent que s'il est invisible.
  const APPAREILS = {
    bureau: { nom: "ordinateur", ratio: 3 / 2 },
    mobile: { nom: "téléphone", ratio: 9 / 19 },
  };
  const ZOOM_MIN = 100;
  const ZOOM_MAX = 300;
  // Une photo d'accueil s'affiche en grand : on la réduit moins que les photos d'œuvres (1920 px).
  const PREPARE_OPTIONS = { maxDimension: 2560, quality: 0.85 };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  function init() {
    const form = document.querySelector("form.ea-edit-form");
    if (!form || !form.querySelector('[name="Accueil[bureauMode]"]')) {
      return;
    }

    const photos = {
      principale: { input: form.querySelector('input[type="file"][data-accueil-photo="principale"]'), champ: "imageFile", fichier: null, url: null },
      mobile: { input: form.querySelector('input[type="file"][data-accueil-photo="mobile"]'), champ: "imageMobileFile", fichier: null, url: null },
    };
    const couleur = form.querySelector('[name="Accueil[couleurFond]"]');
    const widgets = Object.keys(APPAREILS).map((cle) => construire(form, cle));
    const rafraichirTout = () => widgets.forEach((w) => w.rafraichir());

    Object.values(photos).forEach((photo) => {
      if (photo.input) {
        photo.input.addEventListener("change", () => choisirPhoto(photo));
      }
    });
    // Cases « Retirer cette photo » (créées par le formulaire seulement quand une photo existe déjà)
    form.addEventListener("change", (event) => {
      if (event.target.matches && event.target.matches('input[type="checkbox"][name$="[delete]"]')) {
        rafraichirTout();
      }
    });
    if (couleur) {
      couleur.addEventListener("input", rafraichirTout);
    }

    rafraichirTout();

    // --- Photos -------------------------------------------------------------------------------

    // Adresse de la photo à montrer : celle qui vient d'être choisie, sinon celle déjà enregistrée
    // (sauf si « Retirer cette photo » est cochée).
    function adresse(cle) {
      const photo = photos[cle];
      if (!photo.input) {
        return null;
      }
      if (photo.fichier) {
        return photo.url;
      }
      const retirer = form.querySelector('input[type="checkbox"][name="Accueil[' + photo.champ + '][delete]"]');
      if (retirer && retirer.checked) {
        return null;
      }

      return photo.input.dataset.previewUrl || null;
    }

    // Le téléphone montre sa propre photo si elle existe, sinon la photo principale.
    function adresseAppareil(appareil) {
      return appareil === "mobile" ? adresse("mobile") || adresse("principale") : adresse("principale");
    }

    let derniereSelection = 0;

    async function choisirPhoto(photo) {
      const input = photo.input;
      const courante = ++derniereSelection;
      oublier(photo);
      retirerMessage(input);

      const choisie = input.files && input.files[0];
      if (!choisie) {
        rafraichirTout();
        return;
      }

      // Même préparation que pour les photos d'articles : réduite, et convertie si c'est une photo d'iPhone.
      window.CarotteImages?.blockSubmit(true);
      try {
        let prete = choisie;
        if (window.CarotteImages) {
          prete = await window.CarotteImages.prepare(choisie, PREPARE_OPTIONS);
        }
        if (courante !== derniereSelection) {
          return;
        }
        if (prete !== choisie) {
          const transfert = new DataTransfer();
          transfert.items.add(prete);
          input.files = transfert.files;
        }

        // Le serveur jette tout le formulaire (sans rien expliquer) si le fichier dépasse sa limite.
        const max = parseInt(input.dataset.maxBytes || "0", 10);
        if (max > 0 && prete.size > max) {
          input.value = "";
          afficherMessage(input, "Cette photo pèse " + formaterMo(prete.size) + " : le serveur n'accepte pas plus de " + formaterMo(max) + ". Choisis une photo plus légère.");
          rafraichirTout();
          return;
        }

        photo.fichier = prete;
        photo.url = URL.createObjectURL(prete);
      } finally {
        window.CarotteImages?.blockSubmit(false);
      }
      rafraichirTout();
    }

    function oublier(photo) {
      if (photo.url) {
        URL.revokeObjectURL(photo.url);
      }
      photo.fichier = null;
      photo.url = null;
    }

    // --- Un bloc « cadre + aperçu » par appareil ------------------------------------------------

    function construire(form, cle) {
      const appareil = APPAREILS[cle];
      const champ = (nom) => form.querySelector('[name="Accueil[' + cle + nom + ']"]');
      const modes = Array.from(form.querySelectorAll('input[type="radio"][name="Accueil[' + cle + 'Mode]"]'));
      const champX = champ("X");
      const champY = champ("Y");
      const champZoom = champ("Zoom");
      if (!modes.length || !champX || !champY || !champZoom) {
        return { rafraichir() {} };
      }

      // Les champs chiffrés sont remplis par le cadre et le curseur : on les cache (ils restent dans le formulaire)
      [champX, champY, champZoom].forEach((c) => {
        const colonne = c.closest(".form-group")?.parentElement;
        if (colonne) {
          colonne.classList.add("accueil-cache");
        }
      });

      const racine = element("div", "accueil-cadrage");
      racine.innerHTML =
        '<p class="accueil-cadrage__vide" hidden>Choisis d\'abord une photo, en haut de la page : l\'aperçu apparaîtra ici.</p>' +
        '<div class="accueil-cadrage__corps">' +
        '  <div class="accueil-cadrage__choix">' +
        '    <p class="accueil-cadrage__titre">Partie de la photo à garder</p>' +
        '    <div class="accueil-picker" tabindex="0" role="group"></div>' +
        '    <div class="accueil-cadrage__zoom">' +
        '      <label>Zoom <input type="range" min="' + ZOOM_MIN + '" max="' + ZOOM_MAX + '" step="5"> <output></output></label>' +
        '      <button type="button" class="btn btn-secondary btn-sm">Recentrer</button>' +
        "    </div>" +
        "  </div>" +
        '  <div class="accueil-cadrage__resultat">' +
        '    <p class="accueil-cadrage__titre">Aperçu sur ' + appareil.nom + "</p>" +
        '    <div class="accueil-apercu accueil-apercu--' + cle + '"><img alt=""></div>' +
        "  </div>" +
        "</div>" +
        '<p class="accueil-cadrage__note form-text"></p>';

      const vide = racine.querySelector(".accueil-cadrage__vide");
      const corps = racine.querySelector(".accueil-cadrage__corps");
      const choix = racine.querySelector(".accueil-cadrage__choix");
      const picker = racine.querySelector(".accueil-picker");
      const curseur = racine.querySelector('input[type="range"]');
      const valeurZoom = racine.querySelector("output");
      const recentrer = racine.querySelector("button");
      const apercu = racine.querySelector(".accueil-apercu");
      const imageApercu = apercu.querySelector("img");
      const note = racine.querySelector(".accueil-cadrage__note");

      picker.setAttribute("aria-label", "Choisir la partie de la photo à garder sur " + appareil.nom + " : déplace le cadre avec la souris, le doigt ou les flèches du clavier");
      const imagePicker = element("img");
      imagePicker.alt = "";
      imagePicker.draggable = false;
      const cadre = element("div", "accueil-picker__cadre");
      picker.append(imagePicker, cadre);

      const corpsFieldset = champZoom.closest(".form-fieldset-body");
      const ligne = corpsFieldset && corpsFieldset.querySelector(".row");
      (ligne || corpsFieldset || form).insertAdjacentElement(ligne ? "afterend" : "beforeend", racine);
      corpsFieldset?.closest("fieldset")?.classList.add("accueil-ameliore");

      // Réglages actuels, lus dans le formulaire
      const lire = () => ({
        plein: (modes.find((m) => m.checked) || {}).value === "plein",
        x: borne(parseFloat(champX.value), 0, 100, 50),
        y: borne(parseFloat(champY.value), 0, 100, 50),
        zoom: borne(parseFloat(champZoom.value), ZOOM_MIN, ZOOM_MAX, 100) / 100,
      });
      const ecrire = (champDuFormulaire, valeur) => {
        champDuFormulaire.value = String(Math.round(valeur));
      };

      // Taille du cadre (en fraction de la photo) pour la forme de l'écran et le zoom donnés
      function tailleCadre(zoom) {
        // La forme réellement dessinée (bordure comprise dans le calcul de l'aperçu) : une photo dont la
        // forme est presque celle de l'écran est très sensible à un écart de 2 % sur ce rapport.
        const ratio = apercu.clientWidth > 0 && apercu.clientHeight > 0 ? apercu.clientWidth / apercu.clientHeight : appareil.ratio;
        const s = Math.max(ratio / imagePicker.naturalWidth, 1 / imagePicker.naturalHeight);

        return {
          largeur: Math.min(1, ratio / (imagePicker.naturalWidth * s) / zoom),
          hauteur: Math.min(1, 1 / (imagePicker.naturalHeight * s) / zoom),
        };
      }

      function rafraichir() {
        const url = adresseAppareil(cle);
        const reglages = lire();

        vide.hidden = Boolean(url);
        corps.hidden = !url;
        note.hidden = !url;
        if (!url) {
          return;
        }

        // Aperçu : les mêmes règles que accueil.css
        if (imageApercu.getAttribute("src") !== url) {
          imageApercu.src = url;
        }
        apercu.style.background = couleur ? couleur.value : "#fff";
        imageApercu.style.objectFit = reglages.plein ? "cover" : "contain";
        const pos = reglages.plein ? reglages.x + "% " + reglages.y + "%" : "50% 50%";
        imageApercu.style.objectPosition = pos;
        imageApercu.style.transformOrigin = pos;
        imageApercu.style.transform = "scale(" + (reglages.plein ? reglages.zoom : 1) + ")";

        // Cadre : utile seulement quand la photo est rognée
        choix.hidden = !reglages.plein;
        note.textContent = reglages.plein
          ? "Les écrans n'ont pas tous exactement cette forme : sur un écran plus large ou plus haut, le bord de la photo est un peu plus ou un peu moins rogné."
          : "La photo entière est visible, centrée. Si l'écran n'a pas la même forme qu'elle, des marges apparaissent : elles ont la couleur choisie plus bas, dans « Marges ».";
        curseur.value = String(Math.round(reglages.zoom * 100));
        valeurZoom.textContent = Math.round(reglages.zoom * 100) + " %";

        if (imagePicker.getAttribute("src") !== url) {
          imagePicker.src = url;
        }
        placerCadre();
      }

      function placerCadre() {
        if (!imagePicker.naturalWidth) {
          return; // pas encore chargée : le « load » ci-dessous rappelle cette fonction
        }
        const reglages = lire();
        const { largeur, hauteur } = tailleCadre(reglages.zoom);
        cadre.style.width = largeur * 100 + "%";
        cadre.style.height = hauteur * 100 + "%";
        cadre.style.left = (reglages.x / 100) * (1 - largeur) * 100 + "%";
        cadre.style.top = (reglages.y / 100) * (1 - hauteur) * 100 + "%";
      }
      imagePicker.addEventListener("load", placerCadre);

      // Place le centre du cadre au point (cx, cy) de la photo (en fractions de 0 à 1), sans sortir de la photo
      function centrerSur(cx, cy) {
        const { largeur, hauteur } = tailleCadre(lire().zoom);
        // Sans marge de déplacement dans un sens, la valeur n'a aucun effet : on garde celle d'avant
        if (largeur < 1) {
          ecrire(champX, borne((cx - largeur / 2) / (1 - largeur), 0, 1, 0.5) * 100);
        }
        if (hauteur < 1) {
          ecrire(champY, borne((cy - hauteur / 2) / (1 - hauteur), 0, 1, 0.5) * 100);
        }
        rafraichir();
      }

      // Souris et doigt : on attrape le cadre (sans qu'il saute), ou on clique ailleurs pour l'y amener
      let decalage = null;
      const pointVers = (event) => {
        const rect = imagePicker.getBoundingClientRect();

        return { x: (event.clientX - rect.left) / rect.width, y: (event.clientY - rect.top) / rect.height };
      };
      picker.addEventListener("pointerdown", (event) => {
        if (event.pointerType === "mouse" && event.button !== 0) {
          return;
        }
        event.preventDefault();
        picker.focus({ preventScroll: true });
        picker.setPointerCapture(event.pointerId);

        const p = pointVers(event);
        const cadreRect = cadre.getBoundingClientRect();
        const imageRect = imagePicker.getBoundingClientRect();
        const dansLeCadre = event.clientX >= cadreRect.left && event.clientX <= cadreRect.right && event.clientY >= cadreRect.top && event.clientY <= cadreRect.bottom;
        decalage = dansLeCadre
          ? {
              x: p.x - ((cadreRect.left + cadreRect.width / 2 - imageRect.left) / imageRect.width),
              y: p.y - ((cadreRect.top + cadreRect.height / 2 - imageRect.top) / imageRect.height),
            }
          : { x: 0, y: 0 };
        centrerSur(p.x - decalage.x, p.y - decalage.y);
      });
      picker.addEventListener("pointermove", (event) => {
        if (decalage) {
          const p = pointVers(event);
          centrerSur(p.x - decalage.x, p.y - decalage.y);
        }
      });
      const lacher = () => {
        decalage = null;
      };
      picker.addEventListener("pointerup", lacher);
      picker.addEventListener("pointercancel", lacher);

      // Clavier : flèches (Maj pour aller plus vite)
      picker.addEventListener("keydown", (event) => {
        const pas = event.shiftKey ? 10 : 2;
        const sens = { ArrowLeft: [-pas, 0], ArrowRight: [pas, 0], ArrowUp: [0, -pas], ArrowDown: [0, pas] }[event.key];
        if (!sens) {
          return;
        }
        event.preventDefault();
        const reglages = lire();
        const { largeur, hauteur } = tailleCadre(reglages.zoom);
        if (largeur < 1) {
          ecrire(champX, borne(reglages.x + sens[0], 0, 100, 50));
        }
        if (hauteur < 1) {
          ecrire(champY, borne(reglages.y + sens[1], 0, 100, 50));
        }
        rafraichir();
      });

      curseur.addEventListener("input", () => {
        ecrire(champZoom, curseur.value);
        rafraichir();
      });
      recentrer.addEventListener("click", () => {
        ecrire(champX, 50);
        ecrire(champY, 50);
        ecrire(champZoom, 100);
        rafraichir();
      });
      modes.forEach((m) => m.addEventListener("change", rafraichir));
      // Si quelqu'un modifie les champs chiffrés (par exemple un autre script), le cadre suit
      [champX, champY, champZoom].forEach((c) => c.addEventListener("input", rafraichir));

      return { rafraichir };
    }
  }

  // --- Petits outils -----------------------------------------------------------------------------

  function element(balise, classe) {
    const el = document.createElement(balise);
    if (classe) {
      el.className = classe;
    }

    return el;
  }

  function borne(valeur, min, max, parDefaut) {
    if (!Number.isFinite(valeur)) {
      return parDefaut;
    }

    return Math.min(max, Math.max(min, valeur));
  }

  function formaterMo(octets) {
    return (octets / 1024 / 1024).toFixed(1).replace(".", ",") + " Mo";
  }

  function afficherMessage(input, texte) {
    const message = element("p", "accueil-message form-text text-danger");
    message.setAttribute("role", "alert");
    message.textContent = texte;
    input.closest(".form-widget").appendChild(message);
  }

  function retirerMessage(input) {
    input.closest(".form-widget")?.querySelectorAll(".accueil-message").forEach((m) => m.remove());
  }
})();
