(() => {
  'use strict';

  // Formulaire d'une catégorie « vitrine » : les blocs (texte, image, son, vidéo).
  // EasyAdmin crée et supprime les blocs ; ce script construit autour une interface
  // plus directe :
  //  - une barre « Ajouter » : un texte, ou des images / sons / vidéos (plusieurs à la
  //    fois, par le sélecteur de fichiers ou en les glissant) avec un bloc par fichier ;
  //  - une taille par défaut pour les nouvelles images et vidéos ;
  //  - dans chaque bloc : seuls les champs utiles, un aperçu, un résumé dans l'en-tête ;
  //  - réordonner (glisser-déposer ou flèches ▲▼) et renuméroter la position ;
  //  - refuser tout de suite ce que le serveur refuserait (fichier trop gros, trop de
  //    fichiers, bloc incomplet) : à l'enregistrement, PHP jetterait tout le formulaire.

  const COLLECTION_SELECTOR = '[data-form-type-name-placeholder="__blocsname__"]';
  const SIZE_STORAGE_KEY = 'carotte.vitrine.tailleParDefaut';

  const TYPES = {
    texte: { name: 'Texte', icon: '📝' },
    image: {
      name: 'Image',
      icon: '🖼️',
      extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'],
      accept: 'image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif',
      formats: 'JPG, PNG, GIF, WebP ou HEIC',
      article: 'une image',
    },
    son: {
      name: 'Son',
      icon: '🎵',
      extensions: ['mp3', 'm4a', 'ogg', 'wav'],
      accept: 'audio/mpeg,audio/mp4,audio/ogg,audio/wav,.mp3,.m4a,.ogg,.wav',
      formats: 'MP3, M4A, OGG ou WAV',
      article: 'un son',
    },
    video: {
      name: 'Vidéo',
      icon: '🎬',
      extensions: ['mp4', 'webm'],
      accept: 'video/mp4,video/webm,.mp4,.webm',
      formats: 'MP4 ou WebM',
      article: 'une vidéo',
    },
  };

  const FILE_TYPES = ['image', 'son', 'video'];

  // Valeur enregistrée → libellé court (les pourcentages sont dans Bloc::LARGEURS).
  const SIZES = { pleine: 'Grande', moyenne: 'Moyenne', etroite: 'Petite' };
  const SIZE_DETAILS = { pleine: '100 %', moyenne: '65 %', etroite: '40 %' };
  const DEFAULT_SIZE = 'pleine';

  // Quels types de bloc utilisent chaque champ.
  const FIELD_TYPES = {
    contenu: ['texte'],
    titre: ['image', 'son', 'video'],
    largeur: ['image', 'video'],
    fichierFile: ['image', 'son', 'video'],
  };

  const TITLE_LABELS = {
    image: "Description de l'image (obligatoire : lue par les personnes qui ne la voient pas)",
    son: 'Titre du morceau (affiché au-dessus du lecteur)',
    video: 'Titre de la vidéo (facultatif)',
  };

  const FILE_LABELS = { image: 'Image', son: 'Fichier son', video: 'Fichier vidéo' };

  // ---------------------------------------------------------------- utilitaires

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function button(className, text, title, onClick) {
    const node = el('button', className, text);
    node.type = 'button';
    if (title) {
      node.title = title;
      node.setAttribute('aria-label', title);
    }
    node.addEventListener('click', onClick);
    return node;
  }

  const fieldOf = (item, name) =>
    name === 'fichierFile'
      ? item.querySelector('input[type="file"]')
      : item.querySelector(`[name$="[${name}]"]`);

  const groupOf = (field) => field?.closest('.form-group');

  const itemsOf = (collection) => [...collection.querySelectorAll('.field-collection-item')];

  const typeRadios = (item) => [...item.querySelectorAll('input[type="radio"][name$="[type]"]')];

  const sizeRadios = (item) => [...item.querySelectorAll('input[type="radio"][name$="[largeur]"]')];

  const currentType = (item) => typeRadios(item).find((radio) => radio.checked)?.value ?? 'texte';

  const currentSize = (item) => sizeRadios(item).find((radio) => radio.checked)?.value ?? DEFAULT_SIZE;

  // « 2 Mo », « 1,5 Mo » (même présentation que l'aide du champ fichier, côté serveur)
  const megabytes = (bytes) => `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',').replace(/,0$/, '')} Mo`;

  function plainText(html) {
    // DOMParser ne charge rien et n'exécute rien : sûr même avec du HTML collé.
    const doc = new DOMParser().parseFromString(html || '', 'text/html');
    return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
  }

  // « chant-du_matin.mp3 » → « chant du matin » : une description de départ pour une image
  // (comme la légende d'une photo d'article), à corriger si besoin.
  function humanize(filename) {
    const name = filename
      .replace(/\.[^.]+$/, '')
      .replace(/[_\-.]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    return name.slice(0, 255);
  }

  function typeOfFile(file) {
    const extension = (file.name.split('.').pop() || '').toLowerCase();

    return FILE_TYPES.find((type) => TYPES[type].extensions.includes(extension)) ?? null;
  }

  // Les enregistrements « automatiques » (ajout de blocs, réordonnancement) ne passent par
  // aucun champ : on le dit au formulaire pour que l'alerte « modifications non enregistrées » les voie.
  function markDirty(node) {
    node.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function rememberedSize() {
    try {
      const value = window.localStorage.getItem(SIZE_STORAGE_KEY);
      return value in SIZES ? value : DEFAULT_SIZE;
    } catch {
      return DEFAULT_SIZE;
    }
  }

  function rememberSize(value) {
    try {
      window.localStorage.setItem(SIZE_STORAGE_KEY, value);
    } catch {
      // Navigation privée, stockage bloqué… : la taille par défaut sera juste « Grande » la prochaine fois.
    }
  }

  // ------------------------------------------------------------ limites du serveur

  function limitsOf(collection) {
    const read = (name) => Number(collection.dataset[name]) || 0;

    return { maxBytes: read('maxBytes'), maxTotalBytes: read('maxTotalBytes'), maxFiles: read('maxFiles') };
  }

  function usageOf(collection, exceptInput = null) {
    let count = 0;
    let bytes = 0;

    collection.querySelectorAll('.field-collection-item input[type="file"]').forEach((input) => {
      if (input === exceptInput) return;
      for (const file of input.files ?? []) {
        count += 1;
        bytes += file.size;
      }
    });

    return { count, bytes };
  }

  // Renvoie null si le fichier peut être ajouté, sinon { text, stop } : stop = inutile
  // d'essayer les suivants (la limite du nombre ou du poids total est atteinte).
  function limitProblem(collection, file, exceptInput = null) {
    const { maxBytes, maxTotalBytes, maxFiles } = limitsOf(collection);

    if (maxBytes && file.size > maxBytes) {
      return {
        stop: false,
        text:
          `« ${file.name} » fait ${megabytes(file.size)} : le serveur n'accepte pas plus de ${megabytes(maxBytes)} par fichier. ` +
          "Compresse-le (ou choisis-en un plus léger) avant de l'ajouter.",
      };
    }

    const usage = usageOf(collection, exceptInput);

    if (maxFiles && usage.count + 1 > maxFiles) {
      return {
        stop: true,
        text:
          `Le serveur accepte au plus ${maxFiles} fichiers à chaque enregistrement. ` +
          'Enregistre la page, puis ajoute la suite.',
      };
    }

    // Une marge pour le reste du formulaire (textes, en-têtes de l'envoi…).
    if (maxTotalBytes && usage.bytes + file.size > Math.floor(maxTotalBytes * 0.9)) {
      return {
        stop: true,
        text:
          `Le serveur accepte au plus ${megabytes(maxTotalBytes)} à chaque enregistrement, tous fichiers confondus. ` +
          'Enregistre la page, puis ajoute la suite.',
      };
    }

    return null;
  }

  // ------------------------------------------------------------------- messages

  function say(collection, lines) {
    const box = collection.querySelector('.vitrine-messages');
    if (!box) return;

    box.replaceChildren(
      ...lines.map(({ kind, text }) => el('p', `vitrine-message vitrine-message--${kind}`, text)),
    );
  }

  // --------------------------------------------------- aperçu et en-tête d'un bloc

  // URL temporaire du fichier choisi (pas encore envoyé), par bloc : à libérer quand elle change.
  const objectUrls = new WeakMap();

  function releaseUrl(item) {
    const entry = objectUrls.get(item);
    if (entry) URL.revokeObjectURL(entry.url);
    objectUrls.delete(item);
  }

  // Adresse du fichier à montrer : celui qu'on vient de choisir, sinon celui déjà enregistré.
  function mediaUrl(item) {
    const input = fieldOf(item, 'fichierFile');
    if (!input) return '';

    const file = input.files?.[0] ?? null;
    let entry = objectUrls.get(item);

    if (entry && entry.file !== file) {
      releaseUrl(item);
      entry = undefined;
    }
    if (file && !entry) {
      entry = { file, url: URL.createObjectURL(file) };
      objectUrls.set(item, entry);
    }

    return entry ? entry.url : input.dataset.previewUrl || '';
  }

  function renderPreview(item) {
    const box = item.querySelector('.vitrine-bloc-preview');
    if (!box) return;

    const type = currentType(item);
    const url = FILE_TYPES.includes(type) ? mediaUrl(item) : '';

    box.classList.toggle('d-none', !url);
    box.dataset.largeur = currentSize(item);

    // On ne recrée le lecteur que si le fichier a changé (changer la taille ne doit pas le relancer).
    const key = `${type}|${url}`;
    if (box.dataset.key === key) return;
    box.dataset.key = key;

    const frame = box.querySelector('.vitrine-bloc-preview__frame');
    frame.replaceChildren();
    box.dataset.kind = type;
    if (!url) return;

    let media;
    if (type === 'image') {
      media = el('img');
      media.alt = '';
    } else if (type === 'son') {
      media = el('audio');
      media.controls = true;
      media.preload = 'metadata';
    } else {
      media = el('video');
      media.controls = true;
      media.preload = 'metadata';
      media.playsInline = true;
    }
    media.src = url;
    frame.append(media);
  }

  function updateBadge(item) {
    const badge = item.querySelector('.vitrine-bloc-badge');
    if (!badge) return;

    const type = currentType(item);
    const url = type === 'image' ? mediaUrl(item) : '';
    const key = url ? `thumb|${url}` : `icon|${type}`;
    if (badge.dataset.key === key) return;
    badge.dataset.key = key;

    if (url) {
      const thumb = el('img', 'vitrine-bloc-thumb');
      thumb.alt = '';
      thumb.src = url;
      badge.replaceChildren(thumb);
    } else {
      badge.replaceChildren(el('span', 'vitrine-bloc-icon', TYPES[type]?.icon ?? '▫️'));
    }
  }

  function updateLabel(item) {
    const label = item.querySelector('.vitrine-bloc-label');
    if (!label) return;

    const type = currentType(item);
    let summary = type === 'texte'
      ? plainText(fieldOf(item, 'contenu')?.value)
      : (fieldOf(item, 'titre')?.value ?? '').trim();

    if (summary.length > 60) summary = `${summary.slice(0, 60)}…`;

    const name = TYPES[type]?.name ?? 'Bloc';
    label.textContent = summary ? `${name} — ${summary}` : name;

    const chip = item.querySelector('.vitrine-bloc-size');
    if (chip) {
      const sized = FIELD_TYPES.largeur.includes(type);
      chip.classList.toggle('d-none', !sized);
      chip.textContent = SIZES[currentSize(item)] ?? '';
      chip.title = `Taille sur la page : ${SIZE_DETAILS[currentSize(item)] ?? ''}`;
    }

    updateBadge(item);
    renderPreview(item);
  }

  function setExpanded(item, expanded) {
    const toggle = item.querySelector('.accordion-button');
    const body = item.querySelector('.accordion-collapse');
    if (!toggle || !body) return;

    toggle.classList.toggle('collapsed', !expanded);
    toggle.setAttribute('aria-expanded', String(expanded));
    body.classList.toggle('show', expanded);
  }

  function applyType(item) {
    const type = currentType(item);

    for (const [name, types] of Object.entries(FIELD_TYPES)) {
      groupOf(fieldOf(item, name))?.classList.toggle('d-none', !types.includes(type));
    }

    // Le type est choisi par le bouton d'ajout, plus par un choix dans le bloc.
    groupOf(typeRadios(item)[0])?.classList.add('d-none');

    const titre = fieldOf(item, 'titre');
    const titreLabel = titre?.id ? item.querySelector(`label[for="${titre.id}"]`) : null;
    if (titreLabel && TITLE_LABELS[type]) titreLabel.textContent = TITLE_LABELS[type];

    const file = fieldOf(item, 'fichierFile');
    if (file && TYPES[type]?.accept) file.accept = TYPES[type].accept;
    const fileLabel = groupOf(file)?.querySelector(':scope > legend, :scope > label');
    if (fileLabel && FILE_LABELS[type]) fileLabel.textContent = FILE_LABELS[type];

    item.dataset.type = type;
    updateLabel(item);
  }

  // ------------------------------------------------------- numérotation, ordre

  function renumber(collection) {
    itemsOf(collection).forEach((item, index) => {
      const position = fieldOf(item, 'position');
      if (position) position.value = String(index);
    });

    refreshTools(collection);
  }

  function move(collection, item, direction) {
    const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling?.matches('.field-collection-item')) return;

    if (direction < 0) sibling.before(item);
    else sibling.after(item);

    renumber(collection);
    markDirty(item);
    item.querySelector('.vitrine-bloc-move')?.blur();
  }

  // ------------------------------------------------------------ fichier d'un bloc

  function showFileName(input, file) {
    const name = document.getElementById(`${input.id}_new_file_name`);
    if (!name) return;

    const big = file.size > 1024 * 1024;
    const size = big ? file.size / (1024 * 1024) : file.size / 1024;
    name.textContent = `${file.name} (${size.toFixed(2)} ${big ? 'MB' : 'KB'})`;
  }

  function setFile(item, file) {
    const input = fieldOf(item, 'fichierFile');
    if (!input) return;

    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;

    showFileName(input, file);
    updateLabel(item);
  }

  function clearFile(item) {
    const input = fieldOf(item, 'fichierFile');
    if (!input) return;

    input.value = '';
    const name = document.getElementById(`${input.id}_new_file_name`);
    if (name) name.textContent = '';
    updateLabel(item);
  }

  async function prepareImage(file) {
    if (!window.CarotteImages) return file;

    try {
      return await window.CarotteImages.prepare(file);
    } catch {
      return file; // jamais bloquant : le fichier part tel quel
    }
  }

  // Fichier choisi directement dans un bloc (bouton « Choisir un fichier » d'EasyAdmin).
  async function onFileChosen(collection, item, input) {
    const error = groupOf(input)?.querySelector('.vitrine-bloc-error');
    error?.remove();

    const file = input.files?.[0];
    if (!file) {
      updateLabel(item);
      return;
    }

    const type = currentType(item);
    const reject = (text) => {
      clearFile(item);
      const message = el('div', 'vitrine-bloc-error text-danger small mt-1', text);
      (groupOf(input)?.querySelector('.form-widget') ?? groupOf(input))?.append(message);
    };

    if (typeOfFile(file) !== type) {
      reject(`Ce fichier ne convient pas : il faut ${TYPES[type].article} (${TYPES[type].formats}).`);
      return;
    }

    let ready = file;
    if (type === 'image') {
      window.CarotteImages?.blockSubmit(true);
      try {
        ready = await prepareImage(file);
      } finally {
        window.CarotteImages?.blockSubmit(false);
      }

      // Un autre fichier a été choisi (ou le bloc supprimé) pendant ce temps : celui-ci ne compte plus.
      if (!item.isConnected || input.files?.[0] !== file) return;
      if (ready !== file) setFile(item, ready);
    }

    const problem = limitProblem(collection, ready, input);
    if (problem) {
      reject(problem.text);
      return;
    }

    const titre = fieldOf(item, 'titre');
    if (type === 'image' && titre && !titre.value.trim()) titre.value = humanize(ready.name);

    updateLabel(item);
  }

  // ------------------------------------------------------- vérifications avant envoi

  function problemOf(item) {
    const type = currentType(item);

    if (type === 'texte') {
      return plainText(fieldOf(item, 'contenu')?.value) === '' ? 'Écris un texte dans ce bloc (ou supprime-le).' : null;
    }

    const input = fieldOf(item, 'fichierFile');
    const hasFile = (input?.files?.length ?? 0) > 0 || Boolean(input?.dataset.previewUrl);
    if (!hasFile) return `Choisis un fichier : ${TYPES[type]?.article ?? 'un fichier'} (${TYPES[type]?.formats ?? ''}).`;

    if (type === 'image' && (fieldOf(item, 'titre')?.value ?? '').trim() === '') {
      return "Décris l'image en quelques mots : c'est ce que liront les personnes qui ne peuvent pas la voir.";
    }

    return null;
  }

  function clearProblem(item) {
    item.classList.remove('vitrine-bloc--erreur');
    item.querySelector('.vitrine-bloc-problem')?.remove();
  }

  function showProblem(item, text) {
    clearProblem(item);
    item.classList.add('vitrine-bloc--erreur');
    item.querySelector('.accordion-body')?.prepend(el('div', 'vitrine-bloc-problem', text));
    setExpanded(item, true);
  }

  function selectedPageType() {
    return document.querySelector('input[name="Categorie[type]"]:checked')?.value;
  }

  // Renvoie true si l'envoi peut continuer.
  function checkBeforeSubmit(collection) {
    // Si la catégorie n'est pas (ou plus) une vitrine, ses blocs n'ont pas à être complets ici.
    if (selectedPageType() !== 'vitrine') return true;

    const problems = itemsOf(collection)
      .map((item) => ({ item, text: problemOf(item) }))
      .filter(({ text }) => text);

    itemsOf(collection).forEach(clearProblem);
    if (problems.length === 0) return true;

    problems.forEach(({ item, text }) => showProblem(item, text));
    say(collection, [{
      kind: 'error',
      text:
        problems.length === 1
          ? 'Un bloc est à compléter avant d\'enregistrer (signalé en rouge ci-dessous).'
          : `${problems.length} blocs sont à compléter avant d'enregistrer (signalés en rouge ci-dessous).`,
    }]);
    problems[0].item.scrollIntoView({ behavior: 'smooth', block: 'center' });

    return false;
  }

  // ------------------------------------------------------------ créer des blocs

  // EasyAdmin sait ajouter un bloc (son bouton, masqué, déclenche tout ce qu'il faut :
  // gabarit, numérotation des champs, éditeur de texte…) : on s'en sert tel quel.
  function createItem(collection) {
    const addButton = collection.querySelector('.field-collection-add-button');
    if (!addButton) return null;

    const known = new Set(itemsOf(collection));
    addButton.click();

    return itemsOf(collection).find((item) => !known.has(item)) ?? null;
  }

  function chooseType(item, type) {
    const radio = typeRadios(item).find((candidate) => candidate.value === type);
    if (radio) radio.checked = true;
    applyType(item);
  }

  function chooseSize(item, size) {
    const radio = sizeRadios(item).find((candidate) => candidate.value === size);
    if (radio) radio.checked = true;
  }

  function defaultSize(collection) {
    return collection.querySelector('.vitrine-sizes input:checked')?.value ?? DEFAULT_SIZE;
  }

  function addText(collection) {
    const item = createItem(collection);
    if (!item) return;

    chooseType(item, 'texte');
    setExpanded(item, true);
    markDirty(item);
    say(collection, []);

    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
    item.querySelector('.ql-editor')?.focus({ preventScroll: true });
  }

  async function addFiles(collection, fileList, onlyType = null) {
    const files = [...fileList]; // à copier tout de suite : la liste d'un glisser-déposer se vide après l'événement
    if (files.length === 0) return;

    const lines = [];
    const queue = [];

    for (const file of files) {
      const type = typeOfFile(file);
      if (!type) {
        lines.push({ kind: 'warning', text: `« ${file.name} » n'a pas été ajouté : format non pris en charge.` });
      } else if (onlyType && type !== onlyType) {
        lines.push({ kind: 'warning', text: `« ${file.name} » n'a pas été ajouté : ce n'est pas ${TYPES[onlyType].article}.` });
      } else {
        queue.push({ file, type });
      }
    }

    if (queue.length < files.length && queue.length === 0) {
      lines.push({
        kind: 'info',
        text: `Formats acceptés : images (${TYPES.image.formats}), sons (${TYPES.son.formats}), vidéos (${TYPES.video.formats}).`,
      });
    }

    let added = 0;
    let first = null;
    let stopped = false;
    let notAdded = 0;

    window.CarotteImages?.blockSubmit(true);
    try {
      for (const [index, { file, type }] of queue.entries()) {
        if (stopped) {
          notAdded += 1;
          continue;
        }

        if (queue.length > 1) {
          say(collection, [{ kind: 'info', text: `Préparation des fichiers… ${index + 1} sur ${queue.length}` }]);
        }

        const ready = type === 'image' ? await prepareImage(file) : file;

        const problem = limitProblem(collection, ready);
        if (problem) {
          lines.push({ kind: 'warning', text: problem.text });
          notAdded += 1;
          stopped = problem.stop;
          continue;
        }

        const item = createItem(collection);
        if (!item) {
          lines.push({ kind: 'error', text: `« ${file.name} » n'a pas pu être ajouté.` });
          continue;
        }

        chooseType(item, type);
        if (FIELD_TYPES.largeur.includes(type)) chooseSize(item, defaultSize(collection));
        if (type === 'image') {
          const titre = fieldOf(item, 'titre');
          if (titre) titre.value = humanize(ready.name);
        }
        setFile(item, ready);

        // Plusieurs fichiers d'un coup : blocs repliés pour voir l'ensemble d'un coup d'œil.
        setExpanded(item, queue.length === 1);
        markDirty(item);

        first ??= item;
        added += 1;
      }
    } finally {
      window.CarotteImages?.blockSubmit(false);
    }

    if (stopped && notAdded > 0) {
      lines.push({ kind: 'warning', text: `${notAdded} fichier${notAdded > 1 ? 's' : ''} non ajouté${notAdded > 1 ? 's' : ''}.` });
    }

    if (added > 0) {
      lines.unshift({
        kind: 'success',
        text:
          added === 1
            ? 'Bloc ajouté en bas de la page.'
            : `${added} blocs ajoutés en bas de la page, repliés : clique sur l'un d'eux pour changer sa description ou sa taille, glisse ⠿ pour le déplacer.`,
      });
    }

    say(collection, lines);
    first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function applySizeEverywhere(collection) {
    const size = defaultSize(collection);
    let changed = 0;

    itemsOf(collection).forEach((item) => {
      if (!FIELD_TYPES.largeur.includes(currentType(item))) return;
      chooseSize(item, size);
      updateLabel(item);
      changed += 1;
    });

    if (changed > 0) markDirty(collection);

    say(collection, [{
      kind: changed > 0 ? 'success' : 'info',
      text: changed > 0
        ? `Taille « ${SIZES[size]} » appliquée à ${changed} image${changed > 1 ? 's' : ''} ou vidéo${changed > 1 ? 's' : ''}.`
        : 'Aucune image ni vidéo dans la page pour le moment.',
    }]);
  }

  // ----------------------------------------------------------------- la barre

  // Sélecteur de fichiers caché, ouvert par un bouton de la barre. onlyType : n'accepter
  // que ce type de fichier (null = tous).
  function pickerInput(collection, onlyType, accept) {
    const input = el('input');
    input.type = 'file';
    input.multiple = true;
    input.hidden = true;
    input.accept = accept;
    // Pas de « name » : ces champs ne servent qu'à choisir, jamais à envoyer.
    input.addEventListener('change', (event) => {
      // Ce n'est pas encore une modification de la page : addFiles prévient s'il ajoute quelque chose.
      event.stopPropagation();
      const files = [...input.files];
      input.value = '';
      addFiles(collection, files, onlyType);
    });

    return input;
  }

  function sizeSelector(collection) {
    const fieldset = el('fieldset', 'vitrine-sizes');
    fieldset.append(el('legend', 'vitrine-sizes__legend', 'Taille des nouvelles images et vidéos'));

    const choices = el('div', 'vitrine-sizes__choices');
    const initial = rememberedSize();

    Object.entries(SIZES).forEach(([value, label]) => {
      const wrapper = el('span', 'vitrine-size-choice');
      const input = el('input');
      input.type = 'radio';
      input.name = 'vitrine-taille-par-defaut';
      input.value = value;
      input.id = `vitrine-taille-${value}`;
      input.checked = value === initial;
      // Rattaché à aucun formulaire : ce choix n'est pas envoyé au serveur.
      input.setAttribute('form', 'vitrine-sans-formulaire');

      const text = el('label', null, `${label} (${SIZE_DETAILS[value]})`);
      text.htmlFor = input.id;

      wrapper.append(input, text);
      choices.append(wrapper);
    });

    fieldset.append(choices);
    fieldset.append(button('btn btn-sm btn-link', 'Appliquer à toute la page', 'Donner cette taille à toutes les images et vidéos déjà dans la page', () => applySizeEverywhere(collection)));

    fieldset.addEventListener('change', (event) => {
      // Un réglage de la barre n'est pas une modification de la page.
      event.stopPropagation();
      rememberSize(defaultSize(collection));
    });

    return fieldset;
  }

  function hasFiles(event) {
    return [...(event.dataTransfer?.types ?? [])].includes('Files');
  }

  function buildToolbar(collection) {
    const widget = collection.querySelector('.form-widget');
    const list = widget?.querySelector('.ea-form-collection-items');
    if (!widget || !list || widget.querySelector('.vitrine-toolbar')) return;

    const toolbar = el('div', 'vitrine-toolbar');

    const actions = el('div', 'vitrine-toolbar__actions');
    actions.setAttribute('role', 'group');
    actions.setAttribute('aria-label', 'Ajouter un bloc à la page');

    const pickers = {
      image: pickerInput(collection, 'image', TYPES.image.accept),
      son: pickerInput(collection, 'son', TYPES.son.accept),
      video: pickerInput(collection, 'video', TYPES.video.accept),
      all: pickerInput(collection, null, FILE_TYPES.map((type) => TYPES[type].accept).join(',')),
    };

    actions.append(
      button('btn btn-primary', '＋ Texte', 'Ajouter un bloc de texte', () => addText(collection)),
      button('btn btn-secondary', '🖼️ Images…', 'Ajouter une ou plusieurs images', () => pickers.image.click()),
      button('btn btn-secondary', '🎵 Sons…', 'Ajouter un ou plusieurs sons', () => pickers.son.click()),
      button('btn btn-secondary', '🎬 Vidéos…', 'Ajouter une ou plusieurs vidéos', () => pickers.video.click()),
    );

    const dropzone = el(
      'div',
      'vitrine-dropzone',
      'Ou glisse ici tes images, sons et vidéos (autant que tu veux : chaque fichier devient un bloc).',
    );
    dropzone.tabIndex = 0;
    dropzone.setAttribute('role', 'button');
    dropzone.addEventListener('click', () => pickers.all.click());
    dropzone.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        pickers.all.click();
      }
    });

    ['dragenter', 'dragover'].forEach((name) => {
      toolbar.addEventListener(name, (event) => {
        if (!hasFiles(event)) return;
        event.preventDefault();
        toolbar.classList.add('is-dragging');
      });
    });
    toolbar.addEventListener('dragleave', (event) => {
      if (!toolbar.contains(event.relatedTarget)) toolbar.classList.remove('is-dragging');
    });
    toolbar.addEventListener('drop', (event) => {
      if (!hasFiles(event)) return;
      event.preventDefault();
      toolbar.classList.remove('is-dragging');
      addFiles(collection, event.dataTransfer.files);
    });

    const messages = el('div', 'vitrine-messages');
    messages.setAttribute('role', 'status');
    messages.setAttribute('aria-live', 'polite');

    const tools = el('div', 'vitrine-tools');
    tools.append(
      el('span', 'vitrine-tools__count'),
      button('btn btn-sm btn-link', 'Tout replier', 'Replier tous les blocs', () => itemsOf(collection).forEach((item) => setExpanded(item, false))),
      button('btn btn-sm btn-link', 'Tout déplier', 'Déplier tous les blocs', () => itemsOf(collection).forEach((item) => setExpanded(item, true))),
    );

    toolbar.append(actions, dropzone, sizeSelector(collection), messages, ...Object.values(pickers));
    widget.insertBefore(toolbar, list);

    const empty = el('p', 'vitrine-empty', 'Cette page est vide pour le moment : ajoute un premier bloc avec les boutons ci-dessus.');
    widget.insertBefore(tools, list);
    widget.insertBefore(empty, list);
  }

  // Compteur, « tout replier » et message de page vide.
  function refreshTools(collection) {
    const count = itemsOf(collection).length;

    collection.querySelector('.vitrine-empty')?.classList.toggle('d-none', count > 0);
    const tools = collection.querySelector('.vitrine-tools');
    tools?.classList.toggle('d-none', count < 2);

    const counter = collection.querySelector('.vitrine-tools__count');
    if (counter) counter.textContent = `${count} blocs`;
  }

  // ------------------------------------------------------------- mise en place

  function setupItem(collection, item) {
    if (item.dataset.vitrineReady === '1') return;
    item.dataset.vitrineReady = '1';

    // Un bloc neuf démarre en « Texte » (au cas où le formulaire n'aurait rien coché).
    const radios = typeRadios(item);
    if (radios.length > 0 && !radios.some((radio) => radio.checked)) radios[0].checked = true;

    // En-tête : le texte brut d'EasyAdmin est remplacé par une miniature / une icône,
    // un résumé tenu à jour et la taille choisie.
    const toggle = item.querySelector('.accordion-button');
    if (toggle) {
      [...toggle.childNodes].filter((node) => node.nodeType === Node.TEXT_NODE).forEach((node) => node.remove());
      toggle.append(el('span', 'vitrine-bloc-badge'), el('span', 'vitrine-bloc-label'), el('span', 'vitrine-bloc-size badge text-bg-secondary'));
    }

    const header = item.querySelector('.accordion-header');
    if (header) {
      const controls = el('div', 'vitrine-bloc-controls');

      const handle = el('span', 'vitrine-bloc-handle', '⠿');
      handle.title = 'Glisser pour déplacer ce bloc';

      controls.append(
        handle,
        button('btn btn-sm btn-link vitrine-bloc-move', '▲', 'Monter ce bloc', () => move(collection, item, -1)),
        button('btn btn-sm btn-link vitrine-bloc-move', '▼', 'Descendre ce bloc', () => move(collection, item, 1)),
      );
      header.append(controls);
    }

    // Aperçu (miniature, lecteur) en tête du contenu du bloc.
    const preview = el('div', 'vitrine-bloc-preview d-none');
    preview.append(el('div', 'vitrine-bloc-preview__frame'));
    preview.append(el('p', 'vitrine-bloc-preview__note', 'Aperçu : la taille choisie ci-dessous donne la place que le bloc prendra sur la page.'));
    item.querySelector('.accordion-body')?.prepend(preview);

    sizeRadios(item).forEach((radio) => radio.addEventListener('change', () => updateLabel(item)));
    // « input » est aussi émis par l'éditeur de texte à chaque frappe.
    fieldOf(item, 'titre')?.addEventListener('input', () => updateLabel(item));
    fieldOf(item, 'contenu')?.addEventListener('input', () => updateLabel(item));

    // Un bloc signalé incomplet retrouve son aspect normal dès qu'on y touche.
    item.addEventListener('input', () => clearProblem(item));
    item.addEventListener('change', () => clearProblem(item));

    const file = fieldOf(item, 'fichierFile');
    file?.addEventListener('change', () => onFileChosen(collection, item, file));

    applyType(item);
  }

  function ensureSortable(collection) {
    if (!window.Sortable) return; // Les flèches ▲▼ restent disponibles.

    const container = collection.querySelector('.field-collection-item')?.parentElement;
    if (!container || container.dataset.sortableReady === '1') return;
    container.dataset.sortableReady = '1';

    window.Sortable.create(container, {
      draggable: '.field-collection-item',
      handle: '.vitrine-bloc-handle',
      animation: 150,
      onEnd: () => {
        renumber(collection);
        markDirty(collection);
      },
    });
  }

  function setupCollection(collection) {
    collection.classList.add('vitrine-blocs');
    buildToolbar(collection);
    itemsOf(collection).forEach((item) => setupItem(collection, item));
    ensureSortable(collection);
    refreshTools(collection);

    const form = collection.closest('form');
    if (form && form.dataset.vitrineSubmit !== '1') {
      form.dataset.vitrineSubmit = '1';

      // Avant tout autre réglage de l'envoi (spinner du bouton, alerte « modifications non
      // enregistrées »), qu'on doit laisser intacts si on retient l'enregistrement.
      window.addEventListener(
        'submit',
        (event) => {
          if (event.target !== form) return;
          if (!checkBeforeSubmit(collection)) {
            event.preventDefault();
            event.stopImmediatePropagation();
          }
        },
        true,
      );

      // Quoi qu'il arrive, on enregistre l'ordre réellement affiché.
      form.addEventListener('submit', () => {
        form.querySelectorAll(COLLECTION_SELECTOR).forEach(renumber);
      });
    }
  }

  function init() {
    document.querySelectorAll(COLLECTION_SELECTOR).forEach(setupCollection);
  }

  document.addEventListener('ea.collection.item-added', (event) => {
    const { newElement, collection } = event.detail ?? {};
    if (!collection?.matches(COLLECTION_SELECTOR)) return;

    setupItem(collection, newElement);
    ensureSortable(collection);
    renumber(collection);
  });

  // Brouillon automatique (admin_draft.js) : après avoir remis les champs d'un bloc, on rafraîchit
  // ce que ce script affiche autour (champs du bon type, nom du fichier, aperçu, en-tête).
  document.addEventListener('carotte:item-restored', (event) => {
    const item = event.target;
    const collection = item.closest?.(COLLECTION_SELECTOR);
    if (!collection) return;

    setupItem(collection, item);
    applyType(item);

    const input = fieldOf(item, 'fichierFile');
    const file = input?.files?.[0];
    if (input && file) showFileName(input, file);

    updateLabel(item);
  });

  document.addEventListener('carotte:collection-restored', (event) => {
    const collection = event.target;
    if (!collection?.matches?.(COLLECTION_SELECTOR)) return;

    ensureSortable(collection);
    renumber(collection);

    // Comme quand on ajoute plusieurs fichiers d'un coup : blocs repliés pour voir l'ensemble de la page.
    if (itemsOf(collection).length > 1) itemsOf(collection).forEach((item) => setExpanded(item, false));
  });

  document.addEventListener('ea.collection.item-removed', (event) => {
    const { deletedElement, collection } = event.detail ?? {};
    if (!collection?.matches(COLLECTION_SELECTOR)) return;

    if (deletedElement) releaseUrl(deletedElement);
    renumber(collection);
    markDirty(collection);
  });

  document.addEventListener('DOMContentLoaded', init);
  if (document.readyState !== 'loading') init();
})();
