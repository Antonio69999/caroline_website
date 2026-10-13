document.addEventListener("DOMContentLoaded", function () {
  // Formulaire de catégorie : selon le type choisi, on montre soit la description
  // (galerie d'articles), soit le contenu mis en forme (page de texte, ex. le CV),
  // soit les blocs (vitrine, ex. le Préambule).
  // Tous les champs restent dans le formulaire : basculer d'un type à l'autre
  // ne fait rien perdre, on ne fait que masquer ceux qui ne servent pas.
  const radios = document.querySelectorAll('input[name="Categorie[type]"]');

  if (radios.length === 0) {
    return;
  }

  const groupOf = (field) =>
    document.querySelector(`[name="Categorie[${field}]"]`)?.closest(".form-group");

  const descriptionGroup = groupOf("description");
  const contenuGroup = groupOf("contenu");
  const blocsGroup = document.querySelector("[data-ea-collection-field]")?.closest(".form-group");

  function refresh() {
    const checked = document.querySelector('input[name="Categorie[type]"]:checked');
    const type = checked?.value;

    descriptionGroup?.classList.toggle("d-none", type !== "galerie");
    contenuGroup?.classList.toggle("d-none", type !== "texte");

    // Un bloc en erreur reste visible même si le type a changé : sinon l'erreur
    // renvoyée à l'enregistrement serait invisible.
    const hasInvalidBloc = blocsGroup?.querySelector(".field-collection-item.is-invalid") !== null;
    blocsGroup?.classList.toggle("d-none", type !== "vitrine" && !hasInvalidBloc);
  }

  radios.forEach((radio) => radio.addEventListener("change", refresh));
  refresh();
});
