document.addEventListener("DOMContentLoaded", function () {
  // On cible le formulaire de création ou d'édition d'EasyAdmin
  const form = document.querySelector("form.ea-crud-form");

  if (form) {
    form.addEventListener("submit", function () {
      // On cible le bouton de sauvegarde (en bas et en haut de page)
      const submitButtons = document.querySelectorAll(".action-save");

      submitButtons.forEach((btn) => {
        // On garde la largeur actuelle pour éviter que le bouton se rétrécisse bizarrement
        btn.style.width = btn.offsetWidth + "px";

        // On remplace le texte par le spinner Bootstrap natif d'EasyAdmin
        btn.innerHTML =
          '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Envoi en cours...';

        // On désactive le clic pour éviter le double-envoi
        btn.style.pointerEvents = "none";
        btn.classList.add("disabled", "opacity-75");
      });
    });
  }
});
