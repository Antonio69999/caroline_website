document.addEventListener("DOMContentLoaded", function () {
  // Éditeur de texte d'une page de type « texte » (le CV) : ajoute au-dessus
  // de l'éditeur un sélecteur pour lier un mot ou une phrase à un article du site.
  // La liste des articles vient du serveur (attribut data-linkable-articles).
  const textarea = document.querySelector("textarea[data-linkable-articles]");
  const trixElement = textarea
    ? document.querySelector(`trix-editor[input="${textarea.id}"]`)
    : null;

  if (!textarea || !trixElement) {
    return;
  }

  let articles = [];
  try {
    articles = JSON.parse(textarea.dataset.linkableArticles || "[]");
  } catch (error) {
    articles = [];
  }

  const picker = document.createElement("div");
  picker.className = "cv-link-picker";

  if (articles.length === 0) {
    picker.textContent =
      "Aucun article publié pour l'instant : il n'y a rien à citer dans ce texte.";
    trixElement.parentElement.before(picker);
    return;
  }

  const selectId = `${textarea.id}_article_link`;

  const label = document.createElement("label");
  label.htmlFor = selectId;
  label.className = "cv-link-picker__label";
  label.textContent = "Lier vers un article";

  const select = document.createElement("select");
  select.id = selectId;
  select.className = "form-select cv-link-picker__select";

  const placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.textContent = "Choisis un article…";
  select.append(placeholder);

  // Un groupe par catégorie, dans l'ordre du site
  const groups = new Map();
  articles.forEach((article, index) => {
    if (!groups.has(article.categorie)) {
      const group = document.createElement("optgroup");
      group.label = article.categorie;
      groups.set(article.categorie, group);
      select.append(group);
    }

    const option = document.createElement("option");
    option.value = String(index);
    option.textContent = article.titre;
    groups.get(article.categorie).append(option);
  });

  const button = document.createElement("button");
  button.type = "button";
  button.className = "btn btn-secondary btn-sm cv-link-picker__button";
  button.textContent = "Insérer le lien";
  button.disabled = true;

  const hint = document.createElement("p");
  hint.className = "cv-link-picker__hint";
  hint.textContent =
    "Sélectionne d'abord dans le texte le mot ou la phrase qui doit devenir le lien, puis choisis l'article. " +
    "Sans sélection, c'est le titre de l'article qui est inséré.";

  const row = document.createElement("div");
  row.className = "cv-link-picker__row";
  row.append(select, button);

  picker.append(label, row, hint);
  trixElement.parentElement.before(picker);

  // Choisir dans la liste fait perdre le focus à l'éditeur, et Trix oublie alors
  // la sélection : on garde la dernière position connue tant qu'il a le focus.
  let lastRange = null;
  trixElement.addEventListener("trix-selection-change", () => {
    if (trixElement.editor && document.activeElement === trixElement) {
      lastRange = trixElement.editor.getSelectedRange();
    }
  });

  select.addEventListener("change", () => {
    button.disabled = select.value === "";
  });

  button.addEventListener("click", () => {
    const article = articles[Number(select.value)];
    const editor = trixElement.editor;

    if (!article || !editor) {
      return;
    }

    // Pas encore cliqué dans le texte : on ajoute à la fin (le dernier
    // caractère de Trix est toujours un retour à la ligne final).
    const endOfDocument = editor.getDocument().toString().length - 1;
    let [start, end] = lastRange || [endOfDocument, endOfDocument];

    trixElement.focus();
    editor.setSelectedRange([start, end]);

    // Sans texte sélectionné, on insère le titre de l'article comme texte du lien
    if (start === end) {
      editor.insertString(article.titre);
      end = start + article.titre.length;
      editor.setSelectedRange([start, end]);
    }

    editor.activateAttribute("href", article.url);

    // Le curseur repart juste après le lien, sans que le texte tapé ensuite en fasse partie
    editor.setSelectedRange([end, end]);
    editor.deactivateAttribute("href");

    lastRange = [end, end];
    select.value = "";
    button.disabled = true;
  });
});
