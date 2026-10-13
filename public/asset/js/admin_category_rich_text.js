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

  function normalizeHtml(quill) {
    return quill.getText().trim() === '' ? '' : quill.root.innerHTML;
  }

  function initializeEditor({ marker, trix, input }) {
    if (!input || input.dataset.quillEnhanced === '1') return;
    if (!window.Quill) return; // Trix reste visible : fallback sans casse.

    input.dataset.quillEnhanced = '1';
    registerDividerBlot();

    const articles = readArticles(marker, trix, input);
    const field = trix?.closest('.form-group, .field-text_editor, .field-textarea') ?? input.parentElement;

    const shell = document.createElement('div');
    shell.className = 'category-rich-editor';

    const actions = document.createElement('div');
    actions.className = 'category-rich-editor__actions';

    const undoButton = createButton('↶ Annuler', 'undo');
    const redoButton = createButton('↷ Rétablir', 'redo');
    const articleButton = createButton('Lier vers un article', 'article');
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

    const toolbarOptions = [
      [{ header: [1, 2, 3, 4, false] }],
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

    const quill = new Quill(editorHost, {
      theme: 'snow',
      placeholder: 'Écris ici le contenu de la page…',
      modules: {
        toolbar: toolbarOptions,
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

    const articleDialog = createArticleDialog(articles, (article) => {
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
