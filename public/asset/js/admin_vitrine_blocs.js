(() => {
  'use strict';

  // Formulaire d'une catégorie « vitrine » : les blocs (texte, image, son, vidéo).
  // EasyAdmin gère l'ajout et la suppression ; ce script s'occupe du reste :
  //  - n'afficher dans chaque bloc que les champs utiles à son type,
  //  - résumer le bloc dans son en-tête (replié, on reconnaît chaque bloc d'un coup d'œil),
  //  - réordonner (glisser-déposer ou flèches ▲▼) et renuméroter la position,
  //  - refuser tout de suite un fichier plus gros que ce que le serveur accepte.

  const COLLECTION_SELECTOR = '[data-form-type-name-placeholder="__blocsname__"]';

  const TYPE_LABELS = { texte: 'Texte', image: 'Image', son: 'Son', video: 'Vidéo' };

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

  const FILE_ACCEPT = {
    image: 'image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp',
    son: 'audio/mpeg,audio/mp4,audio/ogg,audio/wav,.mp3,.m4a,.ogg,.wav',
    video: 'video/mp4,video/webm,.mp4,.webm',
  };

  const fieldOf = (item, name) =>
    name === 'fichierFile'
      ? item.querySelector('input[type="file"]')
      : item.querySelector(`[name$="[${name}]"]`);

  const groupOf = (field) => field?.closest('.form-group');

  const itemsOf = (collection) => [...collection.querySelectorAll('.field-collection-item')];

  const typeRadios = (item) => [...item.querySelectorAll('input[type="radio"][name$="[type]"]')];

  const currentType = (item) =>
    typeRadios(item).find((radio) => radio.checked)?.value ?? 'texte';

  function plainText(html) {
    // DOMParser ne charge rien et n'exécute rien : sûr même avec du HTML collé.
    const doc = new DOMParser().parseFromString(html || '', 'text/html');
    return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function updateLabel(item) {
    const label = item.querySelector('.vitrine-bloc-label');
    if (!label) return;

    const type = currentType(item);
    let summary = type === 'texte'
      ? plainText(fieldOf(item, 'contenu')?.value)
      : (fieldOf(item, 'titre')?.value ?? '').trim();

    if (summary.length > 60) summary = `${summary.slice(0, 60)}…`;

    const name = TYPE_LABELS[type] ?? 'Bloc';
    label.textContent = summary ? `${name} — ${summary}` : name;
  }

  function applyType(item) {
    const type = currentType(item);

    for (const [name, types] of Object.entries(FIELD_TYPES)) {
      groupOf(fieldOf(item, name))?.classList.toggle('d-none', !types.includes(type));
    }

    const titre = fieldOf(item, 'titre');
    const titreLabel = titre?.id ? item.querySelector(`label[for="${titre.id}"]`) : null;
    if (titreLabel && TITLE_LABELS[type]) titreLabel.textContent = TITLE_LABELS[type];

    const file = fieldOf(item, 'fichierFile');
    if (file && FILE_ACCEPT[type]) file.accept = FILE_ACCEPT[type];

    updateLabel(item);
  }

  function renumber(collection) {
    itemsOf(collection).forEach((item, index) => {
      const position = fieldOf(item, 'position');
      if (position) position.value = String(index);
    });
  }

  function move(collection, item, direction) {
    const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling?.matches('.field-collection-item')) return;

    if (direction < 0) sibling.before(item);
    else sibling.after(item);

    renumber(collection);
    item.querySelector('.vitrine-bloc-move')?.blur();
  }

  function button(className, text, title, onClick) {
    const el = document.createElement('button');
    el.type = 'button';
    el.className = className;
    el.textContent = text;
    el.title = title;
    el.setAttribute('aria-label', title);
    el.addEventListener('click', onClick);
    return el;
  }

  function checkFileSize(input) {
    const max = Number(input.dataset.maxBytes);
    const file = input.files?.[0];
    const group = groupOf(input);

    group?.querySelector('.vitrine-bloc-error')?.remove();
    if (!file || !max || file.size <= max) return;

    // Au-delà, PHP jetterait tout le formulaire : on prévient avant l'envoi.
    input.value = '';
    const name = document.getElementById(`${input.id}_new_file_name`);
    if (name) name.textContent = '';

    const error = document.createElement('div');
    error.className = 'vitrine-bloc-error text-danger small mt-1';
    error.textContent =
      `Ce fichier fait ${(file.size / 1024 / 1024).toFixed(1).replace('.', ',')} Mo : ` +
      `le serveur n'accepte pas plus de ${(max / 1024 / 1024).toFixed(1).replace('.', ',')} Mo. ` +
      'Compresse-le (ou choisis-en un plus léger) avant de l\'envoyer.';
    (group?.querySelector('.form-widget') ?? group)?.append(error);
  }

  function setupItem(collection, item) {
    if (item.dataset.vitrineReady === '1') return;
    item.dataset.vitrineReady = '1';

    // Un bloc neuf démarre en « Texte » (au cas où le formulaire n'aurait rien coché).
    const radios = typeRadios(item);
    if (radios.length > 0 && !radios.some((radio) => radio.checked)) radios[0].checked = true;

    // En-tête : le texte brut d'EasyAdmin est remplacé par un résumé tenu à jour.
    const toggle = item.querySelector('.accordion-button');
    if (toggle) {
      [...toggle.childNodes].filter((node) => node.nodeType === Node.TEXT_NODE).forEach((node) => node.remove());
      const label = document.createElement('span');
      label.className = 'vitrine-bloc-label';
      toggle.append(label);
    }

    const header = item.querySelector('.accordion-header');
    if (header) {
      const controls = document.createElement('div');
      controls.className = 'vitrine-bloc-controls';

      const handle = document.createElement('span');
      handle.className = 'vitrine-bloc-handle';
      handle.textContent = '⠿';
      handle.title = 'Glisser pour déplacer ce bloc';

      controls.append(
        handle,
        button('btn btn-sm btn-link vitrine-bloc-move', '▲', 'Monter ce bloc', () => move(collection, item, -1)),
        button('btn btn-sm btn-link vitrine-bloc-move', '▼', 'Descendre ce bloc', () => move(collection, item, 1)),
      );
      header.append(controls);
    }

    typeRadios(item).forEach((radio) => radio.addEventListener('change', () => applyType(item)));
    // « input » est aussi émis par l'éditeur de texte à chaque frappe.
    fieldOf(item, 'titre')?.addEventListener('input', () => updateLabel(item));
    fieldOf(item, 'contenu')?.addEventListener('input', () => updateLabel(item));

    const file = fieldOf(item, 'fichierFile');
    file?.addEventListener('change', () => checkFileSize(file));

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
      onEnd: () => renumber(collection),
    });
  }

  function setupCollection(collection) {
    collection.classList.add('vitrine-blocs');
    itemsOf(collection).forEach((item) => setupItem(collection, item));
    ensureSortable(collection);

    const form = collection.closest('form');
    if (form && form.dataset.vitrineSubmit !== '1') {
      form.dataset.vitrineSubmit = '1';
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
    newElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  document.addEventListener('ea.collection.item-removed', (event) => {
    const { collection } = event.detail ?? {};
    if (collection?.matches(COLLECTION_SELECTOR)) renumber(collection);
  });

  document.addEventListener('DOMContentLoaded', init);
  if (document.readyState !== 'loading') init();
})();
