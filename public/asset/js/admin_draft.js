(() => {
  'use strict';

  // Brouillon automatique des formulaires de l'admin (articles, catégories, photos).
  //
  // Pendant qu'on remplit un formulaire, son contenu (textes, choix, photos, blocs d'une
  // vitrine…) est gardé en continu dans ce navigateur. Si la page se ferme, si la session
  // expire, si l'enregistrement est refusé (champ manquant, fichier trop lourd, serveur en
  // panne), le brouillon est retrouvé à la réouverture du formulaire : un bandeau propose de
  // le restaurer, avec ses photos.
  //
  // Le brouillon reste sur cet ordinateur : rien n'est envoyé au serveur, donc rien n'apparaît
  // sur le site tant qu'on n'a pas enregistré la page. C'est voulu : une catégorie ou un
  // article enregistrés à moitié seraient visibles tout de suite des visiteurs.
  //
  // Les textes sont gardés dans localStorage, les fichiers dans IndexedDB (trop gros pour
  // localStorage). Si l'un des deux est indisponible (navigation privée…), le formulaire marche
  // comme avant et le script le dit.
  //
  // Les scripts qui construisent leurs propres éléments de formulaire savent se faire remettre
  // leur valeur par des événements :
  //   - carotte:restore (sur le champ, annulable) : l'éditeur de texte met le texte retrouvé dans Quill ;
  //   - carotte:item-restored / carotte:collection-restored : la vitrine rafraîchit ses blocs.

  const FORM_SELECTOR = 'form.ea-new-form, form.ea-edit-form';
  const COLLECTION_SELECTOR = '[data-ea-collection-field][data-form-type-name-placeholder]';
  const ITEM_SELECTOR = '.field-collection-item';

  const PREFIX = 'carotte.brouillon:';
  const SENT_KEY = 'carotte.brouillon.envoi';
  const DB_NAME = 'carotte-brouillons';
  const STORE = 'fichiers';

  const KEEP_MS = 30 * 24 * 60 * 60 * 1000; // un brouillon abandonné disparaît après 30 jours
  const MAX_FILE_BYTES = 80 * 1024 * 1024;
  const MAX_TOTAL_BYTES = 300 * 1024 * 1024;

  const QUIET_MS = 800; // on enregistre quand on arrête de taper…
  const LONGEST_WAIT_MS = 5000; // …mais au plus 5 s après la première modification
  const TICK_MS = 15000; // et on revérifie de temps en temps (modifications sans événement)
  const BASELINE_DELAY_MS = 700; // le formulaire « de départ » est relevé quand les éditeurs sont prêts
  const SENT_FRESH_MS = 15 * 60 * 1000; // un envoi de beaucoup de photos peut être long

  // Jeton de sécurité et boutons d'EasyAdmin : jamais dans un brouillon.
  const IGNORED_NAME = /\[_token\]$|^_token$|^ea\[/;

  // ------------------------------------------------------------------ stockage

  const store = {
    get(key) {
      try {
        return window.localStorage.getItem(key);
      } catch {
        return null;
      }
    },
    set(key, value) {
      try {
        window.localStorage.setItem(key, value);
        return true;
      } catch {
        return false; // plein, bloqué, navigation privée…
      }
    },
    remove(key) {
      try {
        window.localStorage.removeItem(key);
      } catch {
        // rien à faire
      }
    },
    keys() {
      try {
        return Object.keys(window.localStorage).filter((key) => key.startsWith(PREFIX));
      } catch {
        return [];
      }
    },
  };

  const session = {
    get(key) {
      try {
        return window.sessionStorage.getItem(key);
      } catch {
        return null;
      }
    },
    set(key, value) {
      try {
        window.sessionStorage.setItem(key, value);
      } catch {
        // rien à faire
      }
    },
    remove(key) {
      try {
        window.sessionStorage.removeItem(key);
      } catch {
        // rien à faire
      }
    },
  };

  let dbPromise = null;

  function openDb() {
    if (!dbPromise) {
      dbPromise = new Promise((resolve) => {
        try {
          if (!window.indexedDB) {
            resolve(null);
            return;
          }

          const request = window.indexedDB.open(DB_NAME, 1);
          request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE, { keyPath: 'id' }).createIndex('scope', 'scope');
          };
          request.onsuccess = () => resolve(request.result);
          request.onerror = () => resolve(null);
          request.onblocked = () => resolve(null);
        } catch {
          resolve(null);
        }
      });
    }

    return dbPromise;
  }

  // Une transaction IndexedDB ; work(store) peut renvoyer une requête dont on veut le résultat.
  function transaction(mode, work) {
    return openDb().then(
      (db) =>
        new Promise((resolve, reject) => {
          if (!db) {
            reject(new Error('IndexedDB indisponible'));
            return;
          }

          const tx = db.transaction(STORE, mode);
          let result;
          const request = work(tx.objectStore(STORE));
          if (request) request.onsuccess = () => { result = request.result; };

          tx.oncomplete = () => resolve(result);
          tx.onerror = () => reject(tx.error ?? new Error('IndexedDB'));
          tx.onabort = () => reject(tx.error ?? new Error('IndexedDB'));
        }),
    );
  }

  const putFile = (record) => transaction('readwrite', (objects) => objects.put(record));

  const readFiles = (scope) =>
    transaction('readonly', (objects) => objects.index('scope').getAll(scope)).then(
      (records) => new Map((records ?? []).map((record) => [record.id, record.file])),
    );

  // Supprime les fichiers d'un brouillon, sauf ceux de `keep` (les identifiants à conserver).
  function dropFiles(scope, keep = new Set()) {
    return transaction('readwrite', (objects) => {
      const request = objects.index('scope').openCursor(IDBKeyRange.only(scope));
      request.onsuccess = () => {
        const cursor = request.result;
        if (!cursor) return;
        if (!keep.has(cursor.value.id)) cursor.delete();
        cursor.continue();
      };
    });
  }

  // Fichiers dont le brouillon n'existe plus (effacé, périmé…). Ceux d'une heure ou moins sont laissés :
  // un autre onglet est peut-être en train d'enregistrer le texte qui les accompagne.
  function dropOrphanFiles(liveScopes) {
    return transaction('readwrite', (objects) => {
      const request = objects.openCursor();
      request.onsuccess = () => {
        const cursor = request.result;
        if (!cursor) return;
        const { scope: owner, savedAt } = cursor.value;
        if (!liveScopes.has(owner) && Date.now() - (savedAt ?? 0) > 60 * 60 * 1000) cursor.delete();
        cursor.continue();
      };
    });
  }

  // ----------------------------------------------------------- repères de la page

  // « Categorie:new » ou « Categorie:edit:12 » : un brouillon par formulaire.
  function scopeOfPage() {
    const id = document.body?.id ?? '';
    const created = /^ea-new-(.+)$/.exec(id);
    if (created) return `${created[1]}:new`;

    const edited = /^ea-edit-([^-]+)-(.+)$/.exec(id);
    return edited ? `${edited[1]}:edit:${edited[2]}` : null;
  }

  const entityOfScope = (scope) => scope.split(':')[0];

  function entityOfIndexPage() {
    const match = /\bea-index-(\S+)/.exec(document.body?.className ?? '');
    return match ? match[1] : null;
  }

  function pageHasErrors() {
    return Boolean(
      document.querySelector(
        'form.ea-new-form .is-invalid, form.ea-edit-form .is-invalid, form.ea-new-form .invalid-feedback, form.ea-edit-form .invalid-feedback, .alert-danger',
      ),
    );
  }

  // ------------------------------------------------------- lire le formulaire

  const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  const collectionProp = (collection) => /^__(.+)name__$/.exec(collection.dataset.formTypeNamePlaceholder ?? '')?.[1] ?? null;

  const itemPrefix = (prop) => new RegExp(`^.*?\\[${escapeRegExp(prop)}\\]\\[\\d+\\]`);

  const indexInName = (name, prop) => {
    const match = new RegExp(`\\[${escapeRegExp(prop)}\\]\\[(\\d+)\\]`).exec(name);
    return match ? Number(match[1]) : null;
  };

  const itemsOf = (collection) => [...collection.querySelectorAll(ITEM_SELECTOR)];

  function keyOf(el, name, counters) {
    // Un bouton radio ou une case se retrouvent par leur valeur, les autres par leur rang.
    if (el.type === 'radio' || el.type === 'checkbox') return `${name}=${el.value}`;

    const rank = counters.get(name) ?? 0;
    counters.set(name, rank + 1);

    return `${name}#${rank}`;
  }

  // Les champs nommés d'une zone du formulaire. relativeTo : retire le début du nom
  // (« Categorie[blocs][3] ») pour qu'un bloc se retrouve quel que soit son numéro.
  function controlsIn(root, form, { skipItems = false, relativeTo = null } = {}) {
    const controls = [];
    const counters = new Map();

    for (const el of root.querySelectorAll('input, select, textarea')) {
      if (el.form !== form || !el.name || IGNORED_NAME.test(el.name)) continue;
      if (['button', 'submit', 'reset', 'image'].includes(el.type)) continue;
      if (skipItems && el.closest(ITEM_SELECTOR)) continue;

      const name = relativeTo ? el.name.replace(relativeTo, '') : el.name;
      controls.push({ el, name, key: keyOf(el, name, counters) });
    }

    return controls;
  }

  function readControl(el) {
    if (el.type === 'radio' || el.type === 'checkbox') return { checked: el.checked };

    if (el.tagName === 'SELECT') {
      const chosen = [...el.selectedOptions];
      return { values: chosen.map((option) => option.value), labels: chosen.map((option) => option.textContent.trim()) };
    }

    return { value: el.value };
  }

  function readZone(controls) {
    const fields = {};
    const files = {};

    for (const { el, key } of controls) {
      if (el.type === 'file') {
        const list = [...(el.files ?? [])];
        if (list.length > 0) files[key] = list;
      } else {
        fields[key] = readControl(el);
      }
    }

    return { fields, files };
  }

  // Tout ce qui compte dans le formulaire : champs, fichiers, et blocs des collections.
  function takeSnapshot(form) {
    const { fields, files } = readZone(controlsIn(form, form, { skipItems: true }));

    const collections = [...form.querySelectorAll(COLLECTION_SELECTOR)]
      .map((collection) => {
        const prop = collectionProp(collection);
        if (!prop) return null;

        const items = itemsOf(collection).map((item) => {
          const controls = controlsIn(item, form, { relativeTo: itemPrefix(prop) });
          const named = item.querySelector('[name]');

          return { index: named ? indexInName(named.name, prop) : null, ...readZone(controls) };
        });

        return { prop, items };
      })
      .filter(Boolean);

    return { fields, files, collections };
  }

  // ---------------------------------------------------------- empreinte, fichiers

  // Nom, taille et date suffisent à reconnaître un fichier (la même chose existe pour son descripteur).
  const signature = (file) => `${file.name}|${file.size}|${file.lastModified}`;

  function describeFiles(files) {
    return Object.fromEntries(Object.entries(files).map(([key, list]) => [key, list.map(signature)]));
  }

  // Comparer deux états du formulaire : sert à savoir s'il y a quelque chose à garder.
  function fingerprint(snapshot) {
    return JSON.stringify({
      fields: snapshot.fields,
      files: describeFiles(snapshot.files),
      collections: snapshot.collections.map(({ prop, items }) => ({
        prop,
        items: items.map((item) => ({ index: item.index, fields: item.fields, files: describeFiles(item.files) })),
      })),
    });
  }

  function everyFile(snapshot) {
    const files = [];
    const add = (zone) => Object.values(zone).forEach((list) => files.push(...list));

    add(snapshot.files);
    snapshot.collections.forEach(({ items }) => items.forEach((item) => add(item.files)));

    return files;
  }

  const fileId = (scope, file) => `${scope}|${signature(file)}`;

  // ------------------------------------------------------------ remettre une valeur

  function notify(el, ...names) {
    names.forEach((name) => el.dispatchEvent(new Event(name, { bubbles: true })));
  }

  function applyToTomSelect(el, state) {
    const select = el.tomselect;
    const { valueField, labelField } = select.settings;

    // Le choix peut ne pas être dans la liste chargée (recherche à la demande) : on l'y remet.
    state.values.forEach((value, rank) => {
      if (!select.options[value]) select.addOption({ [valueField]: value, [labelField]: state.labels?.[rank] ?? value });
    });
    select.setValue(el.multiple ? state.values : (state.values[0] ?? ''), true);
  }

  // Met la valeur retrouvée dans le champ. Renvoie ce qu'il faut ensuite annoncer au reste de la page.
  function applyControl(el, state) {
    if (el.type === 'radio' || el.type === 'checkbox') {
      const changed = el.checked !== state.checked;
      el.checked = state.checked;

      return changed && state.checked ? ['change'] : [];
    }

    if (el.tagName === 'SELECT') {
      if (el.tomselect) {
        applyToTomSelect(el, state);
      } else {
        state.values.forEach((value, rank) => {
          if (![...el.options].some((option) => option.value === value)) el.add(new Option(state.labels?.[rank] ?? value, value));
        });
        [...el.options].forEach((option) => { option.selected = state.values.includes(option.value); });
      }

      return ['change'];
    }

    if (el.value === state.value) return [];

    // Un éditeur de texte (Quill…) prend le relais s'il le souhaite.
    const handled = !el.dispatchEvent(new CustomEvent('carotte:restore', { cancelable: true, detail: { value: state.value } }));
    if (handled) return [];

    // Éditeur Trix d'EasyAdmin (le champ est un textarea masqué qu'il pilote) : il faut le lui dire,
    // sinon il écraserait le champ à sa prochaine frappe.
    const trix = el.id ? document.querySelector(`trix-editor[input="${CSS.escape(el.id)}"]`) : null;
    if (trix?.editor) {
      trix.editor.loadHTML(state.value);
      el.value = state.value;
    } else {
      el.value = state.value;
    }

    return ['input', 'change'];
  }

  function applyZone(controls, saved, stored, report, { announceFiles }) {
    const pending = [];

    for (const { el, key } of controls) {
      if (el.type === 'file') {
        const wanted = saved.files?.[key];
        if (!wanted?.length) continue;

        const transfer = new DataTransfer();
        for (const description of wanted) {
          const file = stored.get(description.id);
          if (file) transfer.items.add(file);
          else report.missing += 1;
        }

        if (transfer.files.length > 0) {
          el.files = transfer.files;
          if (announceFiles) pending.push([el, ['change']]);
        }
      } else if (saved.fields && key in saved.fields) {
        pending.push([el, applyControl(el, saved.fields[key])]);
      }
    }

    // Tous les champs sont remplis avant que le reste de la page réagisse (ex. le type d'une page qui
    // montre ou cache d'autres champs).
    pending.forEach(([el, names]) => notify(el, ...names));
  }

  // EasyAdmin crée un bloc quand on clique sur son bouton (masqué par la vitrine) : on s'en sert tel quel.
  function createItem(collection) {
    const addButton = collection.querySelector('.field-collection-add-button');
    if (!addButton) return null;

    const known = new Set(itemsOf(collection));
    addButton.click();

    return itemsOf(collection).find((item) => !known.has(item)) ?? null;
  }

  function restoreCollection(form, saved, stored, report) {
    const collection = [...form.querySelectorAll(COLLECTION_SELECTOR)].find((candidate) => collectionProp(candidate) === saved.prop);
    if (!collection) return;

    const prop = saved.prop;
    const prefix = itemPrefix(prop);

    // Un bloc déjà dans la page (enregistré, ou renvoyé après une erreur) reprend ses valeurs ;
    // les autres blocs sont recréés.
    const existing = new Map();
    itemsOf(collection).forEach((item) => {
      const named = item.querySelector('[name]');
      if (named) existing.set(indexInName(named.name, prop), item);
    });

    const paired = [];
    for (const wanted of saved.items) {
      let item = existing.get(wanted.index);
      if (item) existing.delete(wanted.index);
      else item = createItem(collection);

      if (item) paired.push({ item, wanted });
      else report.notCreated += 1;
    }

    for (const { item, wanted } of paired) {
      // Les fichiers d'un bloc sont signalés par le bloc lui-même (carotte:item-restored), pas par « change » :
      // le script de la vitrine ne doit pas les revérifier un par un.
      applyZone(controlsIn(item, form, { relativeTo: prefix }), wanted, stored, report, { announceFiles: false });
    }

    // Même ordre qu'au moment où le brouillon a été pris.
    const container = paired[0]?.item.parentElement;
    if (container) paired.forEach(({ item }) => container.append(item));

    paired.forEach(({ item }) => item.dispatchEvent(new CustomEvent('carotte:item-restored', { bubbles: true })));
    collection.dispatchEvent(new CustomEvent('carotte:collection-restored', { bubbles: true }));
  }

  // --------------------------------------------------------------- interface

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
  }

  function when(timestamp) {
    const date = new Date(timestamp);
    const day = date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });
    const hour = date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

    return `${day} à ${hour}`;
  }

  const plural = (count, one, many) => `${count} ${count > 1 ? many : one}`;

  // ------------------------------------------------------------------ le brouillon

  let form = null;
  let scope = null;
  let baseline = null; // le formulaire tel qu'il est à l'ouverture : on ne garde que ce qui en diffère
  let lastSaved = null;
  let ready = false;
  let waitingForChoice = false; // un brouillon plus ancien attend qu'on le restaure ou le supprime
  let restoring = false;
  let saving = false;
  let again = false;
  let quietTimer = null;
  let firstChangeAt = 0;
  let statusBox = null;
  let storedIds = new Set(); // fichiers déjà dans IndexedDB
  let filesUnavailable = false;

  const draftKey = () => PREFIX + scope;

  function readDraft() {
    const raw = store.get(draftKey());
    if (!raw) return null;

    try {
      const draft = JSON.parse(raw);
      return draft && draft.v === 1 && draft.fields && Array.isArray(draft.collections) ? draft : null;
    } catch {
      return null;
    }
  }

  function discardDraft(targetScope = scope) {
    store.remove(PREFIX + targetScope);
    dropFiles(targetScope).catch(() => {});
    if (targetScope === scope) {
      storedIds = new Set();
      lastSaved = null;
    }
  }

  function describeStoredFiles(files, skipped) {
    return Object.fromEntries(
      Object.entries(files).map(([key, list]) => [
        key,
        list.map((file) => ({
          id: fileId(scope, file),
          name: file.name,
          size: file.size,
          type: file.type,
          lastModified: file.lastModified,
          ...(skipped.has(fileId(scope, file)) ? { skipped: true } : {}),
        })),
      ]),
    );
  }

  // Écrit le texte du brouillon (rapide, synchrone). Renvoie false si le navigateur refuse.
  function writeDraft(snapshot, skipped) {
    const draft = {
      v: 1,
      scope,
      savedAt: Date.now(),
      fields: snapshot.fields,
      files: describeStoredFiles(snapshot.files, skipped),
      collections: snapshot.collections.map(({ prop, items }) => ({
        prop,
        items: items.map((item) => ({ index: item.index, fields: item.fields, files: describeStoredFiles(item.files, skipped) })),
      })),
      skipped: skipped.size,
    };

    return store.set(draftKey(), JSON.stringify(draft)) ? draft : null;
  }

  // Les fichiers vont dans IndexedDB. Renvoie les identifiants qu'on n'a pas pu garder.
  async function saveFiles(snapshot) {
    const skipped = new Set();
    const keep = new Set();
    let total = 0;

    for (const file of everyFile(snapshot)) {
      const id = fileId(scope, file);
      if (keep.has(id) || skipped.has(id)) continue;

      if (filesUnavailable || file.size > MAX_FILE_BYTES || total + file.size > MAX_TOTAL_BYTES) {
        skipped.add(id);
        continue;
      }

      try {
        if (!storedIds.has(id)) await putFile({ id, scope, savedAt: Date.now(), file });
        storedIds.add(id);
        keep.add(id);
        total += file.size;
      } catch {
        // Place insuffisante, stockage bloqué… : on garde le texte, et on prévient.
        skipped.add(id);
        filesUnavailable = true;
      }
    }

    storedIds = keep;
    if (!filesUnavailable) await dropFiles(scope, keep).catch(() => {});

    return skipped;
  }

  async function save() {
    if (!ready || waitingForChoice || restoring) return;

    // Une photo est en cours de réduction : on attend la version réduite, plus légère.
    if (window.CarotteImages?.isBusy?.()) {
      clearTimeout(quietTimer);
      quietTimer = setTimeout(save, 500);
      return;
    }

    if (saving) {
      again = true;
      return;
    }

    saving = true;
    try {
      do {
        again = false;

        const snapshot = takeSnapshot(form);
        const current = fingerprint(snapshot);

        if (current === baseline) {
          // Revenu au point de départ : plus rien à garder.
          if (lastSaved !== null || readDraft()) discardDraft();
          showStatus('clean');
          continue;
        }
        if (current === lastSaved) continue;

        showStatus('saving');
        const skipped = await saveFiles(snapshot);
        const draft = writeDraft(snapshot, skipped);

        if (draft) {
          lastSaved = current;
          showStatus(skipped.size > 0 ? 'partial' : 'saved', draft.savedAt, skipped.size);
        } else {
          showStatus('error');
        }
      } while (again);
    } finally {
      saving = false;
    }
  }

  function schedule(quick = false) {
    if (!ready || waitingForChoice || restoring) return;

    const now = Date.now();
    if (!firstChangeAt) firstChangeAt = now;

    clearTimeout(quietTimer);
    const wait = Math.max(0, Math.min(quick ? 250 : QUIET_MS, firstChangeAt + LONGEST_WAIT_MS - now));
    quietTimer = setTimeout(() => {
      firstChangeAt = 0;
      save();
    }, wait);
  }

  // La page se ferme : seul le texte est sûr d'être écrit à temps (c'est synchrone).
  function flush() {
    if (!ready || waitingForChoice || restoring) return;

    const snapshot = takeSnapshot(form);
    const current = fingerprint(snapshot);
    if (current === baseline || current === lastSaved) return;

    writeDraft(snapshot, new Set());
  }

  // ----------------------------------------------------------------- indicateur

  function showStatus(kind, savedAt = 0, skipped = 0) {
    if (!statusBox) return;

    statusBox.hidden = kind === 'clean';
    statusBox.dataset.state = kind;

    if (kind === 'saving') {
      statusBox.textContent = 'Brouillon en cours d\'enregistrement…';
    } else if (kind === 'saved') {
      statusBox.textContent = `Brouillon gardé · ${new Date(savedAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
      statusBox.title = "Ce brouillon est gardé sur cet ordinateur, pas sur le site : il n'est pas publié tant que tu n'as pas enregistré la page.";
    } else if (kind === 'partial') {
      statusBox.textContent = `Brouillon gardé, sans ${plural(skipped, 'fichier', 'fichiers')} (trop lourd${skipped > 1 ? 's' : ''} pour ce navigateur)`;
    } else if (kind === 'error') {
      statusBox.textContent = "Brouillon impossible dans ce navigateur : pense à enregistrer la page régulièrement.";
    }
  }

  function buildStatus() {
    statusBox = element('div', 'draft-status');
    statusBox.setAttribute('role', 'status');
    statusBox.setAttribute('aria-live', 'polite');
    statusBox.hidden = true;
    document.body.append(statusBox);
  }

  function countFiles(draft) {
    const count = (zone) => Object.values(zone).reduce((sum, list) => sum + list.length, 0);

    return (
      count(draft.files) +
      draft.collections.reduce((sum, { items }) => sum + items.reduce((inner, item) => inner + count(item.files), 0), 0)
    );
  }

  function showBanner(draft) {
    const banner = element('div', 'draft-banner');
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', 'Brouillon automatique');

    const files = countFiles(draft);
    const text = element('p', 'draft-banner__text');
    text.append(element('strong', null, 'Brouillon retrouvé. '));
    text.append(
      `Ce que tu étais en train de remplir le ${when(draft.savedAt)}` +
        (files > 0 ? `, avec ${plural(files, 'fichier', 'fichiers')},` : '') +
        " a été gardé sur cet ordinateur. Il n'a pas été enregistré sur le site.",
    );

    const note = element('p', 'draft-banner__note');
    note.setAttribute('role', 'status');
    note.hidden = true;

    const actions = element('div', 'draft-banner__actions');
    const restore = element('button', 'btn btn-primary', 'Restaurer le brouillon');
    const drop = element('button', 'btn btn-outline-secondary', 'Supprimer le brouillon');
    restore.type = 'button';
    drop.type = 'button';
    actions.append(restore, drop);

    banner.append(text, note, actions);
    form.before(banner);

    const finish = () => {
      waitingForChoice = false;
      firstChangeAt = 0;
    };

    drop.addEventListener('click', () => {
      discardDraft();
      finish();
      banner.remove();
    });

    restore.addEventListener('click', async () => {
      restore.disabled = true;
      drop.disabled = true;

      const report = await restoreDraft(draft);
      finish();

      const lines = ['Brouillon restauré : relis le formulaire, puis enregistre la page (bouton en haut à droite) pour la publier sur le site.'];
      if (report.missing > 0) {
        lines.push(`${plural(report.missing, 'fichier n\'a pas pu être retrouvé', 'fichiers n\'ont pas pu être retrouvés')} : il faudra le choisir à nouveau.`);
      }
      if (report.notCreated > 0) lines.push(`${plural(report.notCreated, 'bloc n\'a pas pu être recréé', 'blocs n\'ont pas pu être recréés')}.`);

      // Le bandeau ne parle plus que de ce qui vient d'être fait.
      text.remove();
      note.textContent = lines.join(' ');
      note.hidden = false;
      actions.remove();

      save();
    });
  }

  async function restoreDraft(draft) {
    const report = { missing: 0, notCreated: 0 };
    restoring = true;

    try {
      let stored = new Map();
      try {
        stored = await readFiles(scope);
      } catch {
        // Fichiers inaccessibles : on remet au moins le texte (chaque fichier absent est compté plus bas).
      }
      storedIds = new Set(stored.keys());

      applyZone(controlsIn(form, form, { skipItems: true }), draft, stored, report, { announceFiles: true });
      draft.collections.forEach((saved) => restoreCollection(form, saved, stored, report));
    } finally {
      restoring = false;
    }

    return report;
  }

  // -------------------------------------------------- enregistrement réussi ou non

  // Au clic sur « Enregistrer » : on retient que ce formulaire part. La page suivante dira si ça a marché.
  function watchSubmission() {
    form.addEventListener('submit', (event) => {
      // D'autres scripts peuvent retenir l'envoi (champ obligatoire vide…) : alors rien ne part.
      setTimeout(() => {
        if (!event.defaultPrevented) {
          session.set(SENT_KEY, JSON.stringify({ scope, at: Date.now(), url: window.location.href.split('#')[0] }));
        }
      }, 0);
    });
  }

  function readSent() {
    try {
      return JSON.parse(session.get(SENT_KEY) ?? 'null');
    } catch {
      return null;
    }
  }

  // Le brouillon d'un formulaire envoyé n'est supprimé que sur une PREUVE que l'envoi a réussi :
  //   - on arrive par une redirection (le serveur a répondu « c'est fait, va voir la liste »),
  //   - depuis la page du formulaire envoyé (pas depuis une page de connexion, par exemple),
  //   - sur la liste, ou sur un formulaire de la même chose sans erreur.
  // Au moindre doute (page d'erreur, connexion perdue, formulaire renvoyé avec ses erreurs, simple
  // clic sur un lien après un envoi qui n'a pas abouti) le brouillon reste : mieux vaut un bandeau
  // de trop qu'un travail perdu.
  function settleSubmission() {
    const sent = readSent();
    if (!sent) return;
    session.remove(SENT_KEY);

    if (!sent.scope || Date.now() - sent.at > SENT_FRESH_MS) return;

    const navigation = performance.getEntriesByType?.('navigation')?.[0];
    if (!navigation || navigation.type !== 'navigate' || navigation.redirectCount < 1) return;

    const referrer = document.referrer.split('#')[0];
    if (referrer && referrer !== sent.url) return;

    const entity = entityOfScope(sent.scope);
    const onItsList = entityOfIndexPage() === entity;
    const onItsForm = (() => {
      const here = scopeOfPage();
      return here !== null && entityOfScope(here) === entity && !pageHasErrors();
    })();

    if (onItsList || onItsForm) discardDraft(sent.scope);
  }

  // Les brouillons abandonnés ne s'accumulent pas.
  function purgeOldDrafts() {
    const live = new Set();

    for (const key of store.keys()) {
      let savedAt = 0;
      try {
        savedAt = JSON.parse(store.get(key) ?? 'null')?.savedAt ?? 0;
      } catch {
        // illisible : supprimé ci-dessous
      }

      if (Date.now() - savedAt > KEEP_MS) store.remove(key);
      else live.add(key.slice(PREFIX.length));
    }

    dropOrphanFiles(live).catch(() => {});
  }

  // ------------------------------------------------------------------------ départ

  function captureBaselineAndOffer() {
    const snapshot = takeSnapshot(form);
    baseline = fingerprint(snapshot);

    const draft = readDraft();
    if (draft) {
      // Même contenu que la page : c'est un brouillon d'un envoi réussi (ou sans changement).
      if (fingerprint({ fields: draft.fields, files: draft.files, collections: draft.collections }) === baseline) {
        discardDraft();
      } else {
        waitingForChoice = true;
        showBanner(draft);
      }
    }

    ready = true;
  }

  function start() {
    purgeOldDrafts();
    settleSubmission();

    form = document.querySelector(FORM_SELECTOR);
    scope = form ? scopeOfPage() : null;
    if (!form || !scope) return;

    buildStatus();
    watchSubmission();

    const activity = (event) => {
      if (restoring) return;
      schedule(event.target?.type === 'file');
    };
    form.addEventListener('input', activity);
    form.addEventListener('change', activity);
    document.addEventListener('ea.collection.item-added', () => schedule(true));
    document.addEventListener('ea.collection.item-removed', () => schedule(true));

    setInterval(() => schedule(true), TICK_MS);
    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') flush();
    });

    // Le formulaire « de départ » : relevé quand les éditeurs (texte, listes de choix…) ont fini de se mettre en place.
    const arm = () => setTimeout(captureBaselineAndOffer, BASELINE_DELAY_MS);
    if (document.readyState === 'complete') arm();
    else window.addEventListener('load', arm, { once: true });
  }

  window.CarotteDraft = {
    // Le formulaire diffère-t-il de celui d'origine ? (utilisé par l'alerte de fermeture.)
    // null = on ne peut pas encore le dire (la page vient de s'ouvrir) : l'appelant se rabat sur ses propres repères.
    isDirty: () => (ready && form !== null ? fingerprint(takeSnapshot(form)) !== baseline : null),
    saveNow: save,
    discard: () => discardDraft(),
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
