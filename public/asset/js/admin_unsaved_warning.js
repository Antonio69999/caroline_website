document.addEventListener("DOMContentLoaded", function () {
  // On cible le formulaire de création ou d'édition d'EasyAdmin
  const form = document.querySelector("form.ea-crud-form");

  if (!form) {
    return;
  }

  let isDirty = false;

  form.addEventListener("input", () => {
    isDirty = true;
  });
  form.addEventListener("change", () => {
    isDirty = true;
  });

  // On enregistre volontairement : plus besoin d'avertir en quittant la page
  form.addEventListener("submit", () => {
    isDirty = false;
  });

  window.addEventListener("beforeunload", function (event) {
    if (!isDirty) {
      return;
    }
    event.preventDefault();
    event.returnValue = "";
  });
});
