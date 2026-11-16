(() => {
  'use strict';

  const EDITOR_ATTR = 'data-rich-category-editor';
  const ARTICLE_ATTR = 'data-linkable-articles';

  function registerDividerBlot() {
    if (!window.Quill || window.__categoryDividerRegistered) {
      return;
    }

    const BlockEmbed = Quill.import('blots/block/embed');

    class DividerBlot extends BlockEmbed {}
    DividerBlot.blotName = 'divider';
    DividerBlot.tagName = 'hr';

    Quill.register(DividerBlot);
    window.__categoryDividerRegistered = true;
  }

  function escapeSelector(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }

    return String(value).replace(/([ #;?%&,.+*~\\':"!^$[\]()=>|/@])/g, '\\$1');
  }

  function findEditorParts(marker) {
    let trix = marker.matches('trix-editor') ? marker : null;
    let input = null;

    if (trix) {
      const inputId = trix.getAttribute('input');
      if (inputId) {
        input = document.getElementById(inputId);
      }
    } else if (marker.matches('input, textarea')) {
      input = marker;
      if (input.id) {
        trix = document.querySelector(`trix-editor[input="${escapeSelector(input.id)}"]`);
      }
    }

    if (!trix && marker.closest) {
      const field = marker.closest('.form-group, .field-text_editor, .field-textarea');
      trix = field?.querySelector('trix-editor') ?? null;
      if (trix && !input) {
        const inputId = trix.getAttribute('input');
        input = inputId ? document.getElementById(inputId) : null;
      }
    }

    return { trix, input };
  }

  function locateEditors() {
    const markers = [...document.querySelectorAll(`[${EDITOR_ATTR}="1"]`)];
    const found = [];
    const usedInputs = new Set();

    for (const marker of markers) {
      const parts = findEditorParts(marker);
      if (parts.input && !usedInputs.has(parts.input)) {
        usedInputs.add(parts.input);
        found.push({ marker, ...parts });
      }
    }

    // Compatibilité : si EasyAdmin place l'attribut sur un autre noeud,
    // on retrouve le champ "contenu" grâce à son nom/id Symfony.
    if (found.length === 0) {
      const candidates = document.querySelectorAll(
        'input[type="hidden"][name$="[contenu]"], textarea[name$="[contenu]"], input[type="hidden"][id$="_contenu"], textarea[id$="_contenu"]'
      );

      for (const input of candidates) {
        if (usedInputs.has(input)) continue;
        const trix = input.id
          ? document.querySelector(`trix-editor[input="${escapeSelector(input.id)}"]`)
          : null;
        usedInputs.add(input);
        found.push({ marker: input, trix, input });
      }
    }

    return found;
  }

  function readArticles(marker, trix, input) {
    const raw =
      marker?.getAttribute?.(ARTICLE_ATTR) ??
      trix?.getAttribute?.(ARTICLE_ATTR) ??
      input?.getAttribute?.(ARTICLE_ATTR) ??
      '[]';

    try {
      const parsed = JSON.parse(raw || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      console.warn('Impossible de lire la liste des articles liables.', error);
      return [];
    }
  }

  function hideTrix(trix) {
    if (!trix) return;

    trix.classList.add('category-rich-trix-hidden');
    trix.setAttribute('aria-hidden', 'true');
    trix.setAttribute('tabindex', '-1');

    const toolbarId = trix.getAttribute('toolbar');
    const toolbar = toolbarId ? document.getElementById(toolbarId) : null;
    if (toolbar) {
      toolbar.classList.add('category-rich-trix-hidden');
    } else if (trix.previousElementSibling?.matches('trix-toolbar')) {
      trix.previousElementSibling.classList.add('category-rich-trix-hidden');
    }
  }

  function createButton(label, action, title = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-secondary category-rich-action';
    button.dataset.action = action;
    button.textContent = label;
    if (title) button.title = title;
    return button;
  }

  function createArticleDialog(articles, onChoose) {
    const dialog = document.createElement('dialog');
    dialog.className = 'category-article-dialog';

    const header = document.createElement('div');
    header.className = 'category-article-dialog__header';

    const title = document.createElement('strong');
    title.textContent = 'Lier vers un article';

    const close = createButton('Fermer', 'close-article-dialog');
    close.classList.remove('btn-secondary');
    close.classList.add('btn-light');

    header.append(title, close);

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control category-article-dialog__search';
    search.placeholder = 'Rechercher une œuvre ou une catégorie…';

    const list = document.createElement('div');
    list.className = 'category-article-dialog__list';

    const render = () => {
      const query = search.value.trim().toLocaleLowerCase('fr');
      list.innerHTML = '';

      const matches = articles.filter((article) => {
        const haystack = `${article.titre ?? ''} ${article.categorie ?? ''}`.toLocaleLowerCase('fr');
        return !query || haystack.includes(query);
      });

      if (matches.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'category-article-dialog__empty';
        empty.textContent = 'Aucun article trouvé.';
        list.append(empty);
        return;
      }

      for (const article of matches) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'category-article-dialog__item';

        const itemTitle = document.createElement('span');
        itemTitle.className = 'category-article-dialog__item-title';
        itemTitle.textContent = article.titre || 'Article sans titre';

        const category = document.createElement('span');
        category.className = 'category-article-dialog__item-category';
        category.textContent = article.categorie || 'Sans catégorie';

        item.append(itemTitle, category);
        item.addEventListener('click', () => {
          onChoose(article);
          dialog.close();
        });
        list.append(item);
      }
    };

    close.addEventListener('click', () => dialog.close());
    search.addEventListener('input', render);
    dialog.addEventListener('close', () => {
      search.value = '';
      render();
    });

    dialog.append(header, search, list);
    document.body.append(dialog);
    render();

    return {
      open() {
        if (typeof dialog.showModal === 'function') {
          dialog.showModal();
        } else {
          dialog.setAttribute('open', 'open');
        }
        requestAnimationFrame(() => search.focus());
      },
      element: dialog,
    };
  }

  // ---------------------------------------------------------------- polices et titres

  // Polices proposées. « value » est le suffixe de la classe ql-font-xxx posée sur le texte :
  // les styles correspondants (et les fichiers de police) sont dans category_text.css.
  const FONTS = [
    { value: 'alice', label: 'Alice', stack: '"Alice", serif' },
    { value: 'playfair', label: 'Playfair Display', stack: '"Playfair Display", Georgia, serif' },
    { value: 'lora', label: 'Lora', stack: '"Lora", Georgia, serif' },
    { value: 'caveat', label: 'Caveat', stack: '"Caveat", cursive' },
    { value: 'machine', label: 'Machine à écrire', stack: '"Courier New", Courier, monospace' },
  ];
  const DEFAULT_FONT_LABEL = 'Police du site';
  const HEADING_LEVELS = [1, 2, 3, 4, 5, 6];

  function registerFonts() {
    if (!window.Quill || window.__categoryFontsRegistered) {
      return;
    }

    // Par classes (ql-font-xxx) plutôt qu'en style en ligne : le HTML reste propre, et changer
    // une police dans category_text.css la change aussi dans les textes déjà écrits.
    const Font = Quill.import('formats/font');
    Font.whitelist = FONTS.map((font) => font.value);
    Quill.register(Font, true);
    window.__categoryFontsRegistered = true;
  }

  // Taille réelle (px) du texte et de chaque titre dans l'éditeur, lue dans les styles
  // eux-mêmes : le menu ne peut donc pas afficher autre chose que ce qui est à l'écran.
  function measureTextSizes() {
    const probe = document.createElement('div');
    probe.className = 'category-rich-editor';
    probe.setAttribute('aria-hidden', 'true');
    probe.style.cssText = 'position:absolute;left:-9999px;top:0;width:600px;visibility:hidden;pointer-events:none;';

    const container = document.createElement('div');
    container.className = 'ql-container ql-snow';
    const editor = document.createElement('div');
    editor.className = 'ql-editor';

    const paragraph = document.createElement('p');
    paragraph.textContent = 'x';
    editor.append(paragraph);

    const headings = HEADING_LEVELS.map((level) => {
      const heading = document.createElement(`h${level}`);
      heading.textContent = 'x';
      editor.append(heading);
      return heading;
    });

    container.append(editor);
    probe.append(container);
    document.body.append(probe);

    const sizes = { normal: parseFloat(getComputedStyle(paragraph).fontSize) };
    HEADING_LEVELS.forEach((level, index) => {
      sizes[level] = parseFloat(getComputedStyle(headings[index]).fontSize);
    });

    probe.remove();

    return sizes;
  }

  const formatPx = (px) => `${Number(px.toFixed(1))} px`;

  // Écrit dans les menus « Police » et « Titre » ce que chaque choix donne vraiment :
  // « Titre 2 · 20 px », et chaque police dans sa propre police. Quill fabrique ses libellés
  // en CSS (::before), c'est donc une feuille de style qu'on ajoute, une seule fois par page.
  function installPickerLabels() {
    if (document.getElementById('category-rich-picker-labels')) {
      return;
    }

    const sizes = measureTextSizes();
    const picker = '.category-rich-editor .ql-snow .ql-picker';
    const both = (base, selector) => `${base} .ql-picker-label${selector}::before, ${base} .ql-picker-item${selector}::before`;
    const css = [];

    css.push(`${picker}.ql-font { width: 175px; }`);
    css.push(`${both(`${picker}.ql-font`, '')} { content: '${DEFAULT_FONT_LABEL}'; font-family: Verdana, sans-serif; }`);
    for (const font of FONTS) {
      css.push(`${both(`${picker}.ql-font`, `[data-value="${font.value}"]`)} { content: '${font.label}'; font-family: ${font.stack}; }`);
    }

    css.push(`${picker}.ql-header { width: 175px; }`);
    css.push(`${both(`${picker}.ql-header`, '')} { content: 'Texte normal · ${formatPx(sizes.normal)}'; }`);
    for (const level of HEADING_LEVELS) {
      css.push(`${both(`${picker}.ql-header`, `[data-value="${level}"]`)} { content: 'Titre ${level} · ${formatPx(sizes[level])}'; }`);
      // Dans la liste déroulante, chaque titre est écrit à sa taille réelle.
      css.push(`${picker}.ql-header .ql-picker-item[data-value="${level}"]::before { font-size: ${sizes[level]}px; }`);
    }

    const style = document.createElement('style');
    style.id = 'category-rich-picker-labels';
    style.textContent = css.join('\n');
    document.head.append(style);
  }

  // ------------------------------------------------------------------------ lien externe

  // Ce que l'utilisateur tape → une adresse que Quill acceptera, ou une explication.
  function normalizeLink(raw) {
    const value = raw.trim();

    if (value === '') {
      return { error: 'Écris l’adresse du lien.' };
    }
    if (/^(javascript|data|vbscript|file):/i.test(value)) {
      return { error: 'Cette adresse n’est pas acceptée : utilise une adresse de site (https://…), un e-mail ou un numéro (tel:…).' };
    }
    if (/^(https?:\/\/|mailto:|tel:|\/|#)/i.test(value)) {
      return { url: value };
    }
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      return { url: `mailto:${value}` };
    }
    if (/\s/.test(value)) {
      return { error: 'L’adresse ne doit pas contenir d’espace.' };
    }

    // « www.exemple.fr » → « https://www.exemple.fr »
    return { url: `https://${value}` };
  }

  function createLinkDialog({ onSave, onRemove }) {
    const dialog = document.createElement('dialog');
    dialog.className = 'category-article-dialog category-link-dialog';

    const header = document.createElement('div');
    header.className = 'category-article-dialog__header';
    const title = document.createElement('strong');
    title.textContent = 'Lier vers un site';
    const close = createButton('Fermer', 'close-link-dialog');
    close.classList.remove('btn-secondary');
    close.classList.add('btn-light');
    header.append(title, close);

    const form = document.createElement('form');
    form.className = 'category-link-dialog__form';
    form.noValidate = true;

    const field = (label, control) => {
      const row = document.createElement('div');
      row.className = 'category-link-dialog__row';
      const labelNode = document.createElement('label');
      labelNode.textContent = label;
      labelNode.append(control);
      row.append(labelNode);
      return row;
    };

    const selected = document.createElement('p');
    selected.className = 'category-link-dialog__selected';

    const textInput = document.createElement('input');
    textInput.type = 'text';
    textInput.className = 'form-control';
    textInput.placeholder = 'Ex. : Mon site, Voir l’exposition…';
    const textRow = field('Texte du lien', textInput);

    const urlInput = document.createElement('input');
    urlInput.type = 'text';
    urlInput.className = 'form-control';
    urlInput.placeholder = 'https://www.exemple.fr';
    urlInput.setAttribute('autocomplete', 'off');
    urlInput.setAttribute('autocapitalize', 'off');
    urlInput.spellcheck = false;
    const urlRow = field('Adresse du lien', urlInput);

    const help = document.createElement('p');
    help.className = 'category-link-dialog__help';
    help.textContent = 'Un site (https://…), une adresse e-mail ou un numéro de téléphone. Le lien s’ouvre dans un nouvel onglet.';

    const error = document.createElement('p');
    error.className = 'category-link-dialog__error';
    error.setAttribute('role', 'alert');
    error.hidden = true;

    const actions = document.createElement('div');
    actions.className = 'category-link-dialog__actions';
    const save = createButton('Enregistrer le lien', 'save-link');
    save.type = 'submit';
    save.classList.remove('btn-secondary');
    save.classList.add('btn-primary');
    const remove = createButton('Retirer le lien', 'remove-link');
    remove.classList.remove('btn-secondary');
    remove.classList.add('btn-outline-danger');
    const cancel = createButton('Annuler', 'cancel-link');
    cancel.classList.remove('btn-secondary');
    cancel.classList.add('btn-light');
    actions.append(save, remove, cancel);

    form.append(selected, textRow, urlRow, help, error, actions);
    dialog.append(header, form);
    document.body.append(dialog);

    const showError = (message) => {
      error.textContent = message;
      error.hidden = message === '';
    };

    close.addEventListener('click', () => dialog.close());
    cancel.addEventListener('click', () => dialog.close());
    remove.addEventListener('click', () => {
      dialog.close();
      onRemove();
    });
    urlInput.addEventListener('input', () => showError(''));
    textInput.addEventListener('input', () => showError(''));

    form.addEventListener('submit', (event) => {
      event.preventDefault();

      const normalized = normalizeLink(urlInput.value);
      if (normalized.error) {
        showError(normalized.error);
        urlInput.focus();
        return;
      }

      const text = textInput.value.trim();
      if (!textRow.hidden && text === '') {
        showError('Écris le texte qui sera cliquable.');
        textInput.focus();
        return;
      }

      dialog.close();
      onSave({ url: normalized.url, text });
    });

    return {
      // text : le texte déjà sélectionné (ou celui du lien sur lequel on est) ; vide = à écrire ici.
      open({ text, url }) {
        showError('');
        urlInput.value = url;
        textInput.value = '';

        const needsText = text === '';
        textRow.hidden = !needsText;
        selected.hidden = needsText;
        selected.textContent = needsText ? '' : `Texte lié : « ${text.length > 80 ? `${text.slice(0, 80)}…` : text} »`;
        remove.hidden = url === '';
        title.textContent = url === '' ? 'Lier vers un site' : 'Modifier le lien';

        if (typeof dialog.showModal === 'function') {
          dialog.showModal();
        } else {
          dialog.setAttribute('open', 'open');
        }
        requestAnimationFrame(() => (needsText ? textInput : urlInput).focus());
      },
      element: dialog,
    };
  }

  function normalizeHtml(quill) {
    return quill.getText().trim() === '' ? '' : quill.root.innerHTML;
  }

  function initializeEditor({ marker, trix, input }) {
    if (!input || input.dataset.quillEnhanced === '1') return;
    if (!window.Quill) return; // Trix reste visible : fallback sans casse.

    input.dataset.quillEnhanced = '1';
    registerDividerBlot();
    registerFonts();
    installPickerLabels();

    const articles = readArticles(marker, trix, input);
    const field = trix?.closest('.form-group, .field-text_editor, .field-textarea') ?? input.parentElement;

    const shell = document.createElement('div');
    shell.className = 'category-rich-editor';

    const actions = document.createElement('div');
    actions.className = 'category-rich-editor__actions';

    const undoButton = createButton('↶ Annuler', 'undo');
    const redoButton = createButton('↷ Rétablir', 'redo');
    const articleButton = createButton('Lier vers un article', 'article');
    const linkButton = createButton(
      'Lier vers un site',
      'link',
      'Sélectionne un mot ou un groupe de mots, puis clique ici pour en faire un lien vers un site, un e-mail… (raccourci : Ctrl+K)'
    );
    const dividerButton = createButton('Séparateur', 'divider');
    const htmlButton = createButton('</> HTML', 'html', 'Afficher ou modifier le HTML');
    const fullscreenButton = createButton('Plein écran', 'fullscreen');

    if (articles.length === 0) {
      articleButton.disabled = true;
      articleButton.title = 'Aucun article publié disponible.';
    }

    actions.append(
      undoButton,
      redoButton,
      articleButton,
      linkButton,
      dividerButton,
      htmlButton,
      fullscreenButton
    );

    const editorHost = document.createElement('div');
    editorHost.className = 'category-rich-editor__host';
    editorHost.innerHTML = input.value || '';

    const source = document.createElement('textarea');
    source.className = 'form-control category-rich-editor__source';
    source.spellcheck = false;
    source.hidden = true;

    const status = document.createElement('div');
    status.className = 'category-rich-editor__status';

    shell.append(actions, editorHost, source, status);

    if (trix) {
      trix.insertAdjacentElement('afterend', shell);
    } else {
      input.insertAdjacentElement('afterend', shell);
    }

    // Police, puis niveau de titre : les libellés des deux menus sont écrits par installPickerLabels().
    const toolbarOptions = [
      [{ font: [false, ...FONTS.map((font) => font.value)] }],
      [{ header: [...HEADING_LEVELS, false] }],
      ['bold', 'italic', 'underline', 'strike'],
      [{ color: [] }, { background: [] }],
      [{ list: 'ordered' }, { list: 'bullet' }],
      [{ indent: '-1' }, { indent: '+1' }],
      [{ align: [] }],
      [{ script: 'sub' }, { script: 'super' }],
      ['blockquote', 'code-block'],
      ['link'],
      ['clean'],
    ];

    // Le bouton de lien de Quill (et son raccourci Ctrl+K) ne faisait rien sans texte
    // sélectionné et n'affichait qu'une petite bulle : il ouvre maintenant la même boîte
    // que « Lier vers un site » (déclarée plus bas, une fois l'éditeur créé).
    let openLinkDialog = () => {};

    const quill = new Quill(editorHost, {
      theme: 'snow',
      placeholder: 'Écris ici le contenu de la page…',
      modules: {
        toolbar: {
          container: toolbarOptions,
          handlers: { link: () => openLinkDialog() },
        },
        history: {
          delay: 800,
          maxStack: 150,
          userOnly: true,
        },
      },
    });

    // Trix n'est masqué qu'après l'initialisation réussie de Quill.
    // Sans Trix (blocs d'une vitrine : simple textarea), c'est le textarea qu'on masque.
    if (trix) {
      hideTrix(trix);
    } else {
      input.classList.add('category-rich-trix-hidden');
    }

    let sourceMode = false;
    let savedRange = null;

    const syncInput = (notify = true) => {
      const html = sourceMode ? source.value : normalizeHtml(quill);
      input.value = html;

      if (notify) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
    };

    const updateStatus = () => {
      const plain = quill.getText().replace(/\s+/g, ' ').trim();
      const words = plain ? plain.split(' ').length : 0;
      const chars = plain.length;
      status.textContent = `${words} mot${words > 1 ? 's' : ''} · ${chars} caractère${chars > 1 ? 's' : ''}`;
    };

    quill.on('selection-change', (range) => {
      if (range) savedRange = range;
    });

    quill.on('text-change', () => {
      syncInput(true);
      updateStatus();
    });

    updateStatus();
    syncInput(false);

    // Brouillon automatique (admin_draft.js) : un texte retrouvé est remis dans l'éditeur, qui le
    // recopie ensuite dans le champ comme pour une frappe. preventDefault dit « je m'en occupe ».
    input.addEventListener('carotte:restore', (event) => {
      event.preventDefault();
      const html = event.detail?.value ?? '';

      if (sourceMode) {
        source.value = html;
        syncInput(true);
        return;
      }

      quill.clipboard.dangerouslyPasteHTML(html, 'api');
      syncInput(true);
      updateStatus();
    });

    const articleDialog =createArticleDialog(articles, (article) => {
      const range = savedRange ?? quill.getSelection(true) ?? { index: quill.getLength() - 1, length: 0 };
      const title = article.titre || 'Voir l’article';
      const url = article.url;

      if (!url) return;

      if (range.length > 0) {
        quill.formatText(range.index, range.length, 'link', url, 'user');
        quill.setSelection(range.index + range.length, 0, 'silent');
      } else {
        quill.insertText(range.index, title, { link: url }, 'user');
        quill.setSelection(range.index + title.length, 0, 'silent');
      }

      quill.focus();
      syncInput(true);
    });

    // Lien vers un site : sur le texte sélectionné, sur le lien où se trouve le curseur
    // (pour le modifier ou le retirer), ou à insérer avec son texte s'il n'y a rien de sélectionné.
    const LinkBlot = Quill.import('formats/link');
    let linkTarget = { index: 0, length: 0 };

    const linkDialog = createLinkDialog({
      onSave: ({ url, text }) => {
        if (linkTarget.length > 0) {
          quill.formatText(linkTarget.index, linkTarget.length, 'link', url, 'user');
          quill.setSelection(linkTarget.index + linkTarget.length, 0, 'silent');
        } else {
          quill.insertText(linkTarget.index, text, { link: url }, 'user');
          quill.setSelection(linkTarget.index + text.length, 0, 'silent');
        }
        quill.focus();
      },
      onRemove: () => {
        quill.formatText(linkTarget.index, linkTarget.length, 'link', false, 'user');
        quill.focus();
      },
    });

    openLinkDialog = () => {
      const range = quill.getSelection() ?? savedRange ?? { index: Math.max(quill.getLength() - 1, 0), length: 0 };
      let url = '';
      linkTarget = { index: range.index, length: range.length };

      if (range.length === 0) {
        // Curseur dans un lien existant : c'est tout ce lien qui est concerné.
        const [blot, offset] = quill.scroll.descendant(LinkBlot, range.index);
        if (blot) {
          linkTarget = { index: range.index - offset, length: blot.length() };
          url = LinkBlot.formats(blot.domNode) ?? '';
        }
      } else {
        const link = quill.getFormat(range.index, range.length).link;
        url = typeof link === 'string' ? link : '';
      }

      const text = linkTarget.length > 0 ? quill.getText(linkTarget.index, linkTarget.length).replace(/\s+/g, ' ').trim() : '';
      linkDialog.open({ text, url });
    };

    linkButton.addEventListener('click', () => {
      savedRange = quill.getSelection() ?? savedRange;
      openLinkDialog();
    });

    undoButton.addEventListener('click', () => {
      quill.history.undo();
      quill.focus();
    });

    redoButton.addEventListener('click', () => {
      quill.history.redo();
      quill.focus();
    });

    articleButton.addEventListener('click', () => {
      savedRange = quill.getSelection() ?? savedRange;
      articleDialog.open();
    });

    dividerButton.addEventListener('click', () => {
      const range = quill.getSelection(true) ?? { index: quill.getLength() - 1, length: 0 };
      quill.insertEmbed(range.index, 'divider', true, 'user');
      quill.insertText(range.index + 1, '\n', 'user');
      quill.setSelection(range.index + 2, 0, 'silent');
      quill.focus();
    });

    htmlButton.addEventListener('click', () => {
      if (!sourceMode) {
        syncInput(false);
        source.value = input.value;
        editorHost.parentElement.querySelector('.ql-toolbar')?.setAttribute('hidden', 'hidden');
        editorHost.hidden = true;
        source.hidden = false;
        sourceMode = true;
        htmlButton.classList.add('active');
        htmlButton.textContent = 'Retour visuel';
        source.focus();
      } else {
        quill.clipboard.dangerouslyPasteHTML(source.value || '');
        source.hidden = true;
        editorHost.hidden = false;
        editorHost.parentElement.querySelector('.ql-toolbar')?.removeAttribute('hidden');
        sourceMode = false;
        htmlButton.classList.remove('active');
        htmlButton.textContent = '</> HTML';
        syncInput(true);
        updateStatus();
        quill.focus();
      }
    });

    source.addEventListener('input', () => syncInput(true));

    fullscreenButton.addEventListener('click', () => {
      const active = shell.classList.toggle('category-rich-editor--fullscreen');
      document.body.classList.toggle('category-rich-editor-fullscreen-open', active);
      fullscreenButton.textContent = active ? 'Quitter le plein écran' : 'Plein écran';
      quill.focus();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && shell.classList.contains('category-rich-editor--fullscreen')) {
        shell.classList.remove('category-rich-editor--fullscreen');
        document.body.classList.remove('category-rich-editor-fullscreen-open');
        fullscreenButton.textContent = 'Plein écran';
      }
    });

    const form = input.closest('form');
    form?.addEventListener('submit', () => syncInput(false));

    // Compatibilité avec les scripts existants qui repèrent le type EasyAdmin.
    field?.classList.add('category-rich-editor-field');
  }

  function initAll() {
    for (const editor of locateEditors()) {
      initializeEditor(editor);
    }
  }

  document.addEventListener('DOMContentLoaded', initAll);
  document.addEventListener('turbo:load', initAll);
  // Un bloc texte ajouté dans une vitrine arrive après le chargement de la page.
  document.addEventListener('ea.collection.item-added', initAll);

  if (document.readyState !== 'loading') {
    initAll();
  }
})();
