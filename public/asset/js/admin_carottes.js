/*
 * Admin « Carottes cachées » (formulaire d'une carotte).
 *
 * Ce script ajoute trois aides, sans jamais remplacer les vrais champs du formulaire :
 *  - quand « Au hasard » est choisi, les réglages faits à la main (taille, transparence, places)
 *    sont masqués : ils ne servent à rien dans ce mode ;
 *  - l'image choisie est réduite avant l'envoi (comme les photos d'articles) et montrée en aperçu ;
 *  - un exemple de carotte, à la taille et à la transparence choisies, pour juger de ce que
 *    donnera « minuscule » ou « 10 % » avant d'aller voir le site.
 * Sans JavaScript, tous les champs restent visibles et utilisables.
 */
(function () {
  "use strict";

  // Mêmes tailles que public/asset/css/carottes.css
  const TAILLES = { minuscule: 24, petite: 44, normale: 72, grande: 100 };
  // Une image révélée peut s'afficher presque plein écran : on la réduit moins que les photos d'œuvres
  const PREPARE_OPTIONS = { maxDimension: 2000, quality: 0.85 };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  function init() {
    const form = document.querySelector("form.ea-new-form, form.ea-edit-form");
    if (!form || !form.querySelector('[name="Carotte[modeReglage]"]')) {
      return;
    }

    const input = form.querySelector('input[type="file"][data-carotte-image]');
    const apercuImage = ajouterApercuImage(input);
    const apercuCarotte = ajouterApercuCarotte(form, input);

    form.addEventListener("change", rafraichir);
    form.addEventListener("input", rafraichir);
    rafraichir();

    if (input) {
      input.addEventListener("change", () => choisirImage(input, apercuImage));
    }

    function rafraichir() {
      const hasard = valeur("Carotte[modeReglage]") === "hasard";
      form.querySelectorAll(".carotte-reglage").forEach((champ) => {
        champ.hidden = hasard;
      });
      if (apercuCarotte) {
        apercuCarotte.rafraichir(hasard);
      }
    }

    function valeur(nom) {
      const champ = form.querySelector('[name="' + nom + '"]:checked') || form.querySelector('[name="' + nom + '"]:not([type="radio"])');
      return champ ? champ.value : "";
    }

    // --- Exemple de carotte --------------------------------------------------------------------

    function ajouterApercuCarotte(formulaire, champImage) {
      const gif = champImage && champImage.dataset.krot;
      const opacite = formulaire.querySelector('[name="Carotte[carotteOpacite]"]');
      if (!gif || !opacite) {
        return null;
      }

      const boite = document.createElement("div");
      boite.className = "carotte-apercu";
      const legende = document.createElement("p");
      legende.className = "carotte-apercu__legende";
      const zone = document.createElement("div");
      zone.className = "carotte-apercu__zone";
      const img = document.createElement("img");
      img.src = gif;
      img.alt = "";
      zone.appendChild(img);
      boite.append(zone, legende);

      const champ = opacite.closest(".form-group, .field-integer") || opacite.parentElement;
      champ.after(boite);

      return {
        rafraichir(hasard) {
          boite.hidden = hasard;
          const taille = TAILLES[valeur("Carotte[carotteTaille]")] || TAILLES.normale;
          const pourcent = Math.max(5, Math.min(100, parseInt(opacite.value, 10) || 100));
          img.style.width = taille + "px";
          img.style.height = taille + "px";
          img.style.opacity = String(pourcent / 100);
          legende.textContent = "Aperçu : la carotte à " + taille + " px, visible à " + pourcent + " %.";
        },
      };
    }

    // --- Image ---------------------------------------------------------------------------------

    function ajouterApercuImage(champ) {
      if (!champ) {
        return null;
      }
      const img = document.createElement("img");
      img.className = "carotte-image-apercu";
      img.alt = "";
      img.hidden = true;
      champ.closest(".form-widget, .form-group, .field-text") ? champ.closest(".form-widget, .form-group, .field-text").append(img) : champ.after(img);
      montrer(img, champ.dataset.previewUrl || null);

      return img;
    }

    function montrer(img, url) {
      if (!img) {
        return;
      }
      img.hidden = !url;
      if (url) {
        img.src = url;
      }
    }

    async function choisirImage(champ, img) {
      retirerMessage(champ);

      const choisie = champ.files && champ.files[0];
      if (!choisie) {
        montrer(img, champ.dataset.previewUrl || null);
        return;
      }

      // Même préparation que pour les photos d'articles : réduite, et convertie si c'est une photo d'iPhone
      window.CarotteImages?.blockSubmit(true);
      try {
        const prete = window.CarotteImages ? await window.CarotteImages.prepare(choisie, PREPARE_OPTIONS) : choisie;
        if (prete !== choisie) {
          const transfert = new DataTransfer();
          transfert.items.add(prete);
          champ.files = transfert.files;
        }

        // Le serveur jette tout le formulaire (sans rien expliquer) si le fichier dépasse sa limite
        const max = parseInt(champ.dataset.maxBytes || "0", 10);
        if (max > 0 && prete.size > max) {
          champ.value = "";
          afficherMessage(champ, "Cette image pèse " + formaterMo(prete.size) + " : le serveur n'accepte pas plus de " + formaterMo(max) + ". Choisis une image plus légère.");
          montrer(img, champ.dataset.previewUrl || null);
          return;
        }

        montrer(img, URL.createObjectURL(prete));
      } finally {
        window.CarotteImages?.blockSubmit(false);
      }
    }

    function afficherMessage(champ, texte) {
      const message = document.createElement("p");
      message.className = "carotte-image-erreur";
      message.setAttribute("role", "alert");
      message.textContent = texte;
      champ.after(message);
    }

    function retirerMessage(champ) {
      const suivant = champ.parentElement && champ.parentElement.querySelector(".carotte-image-erreur");
      if (suivant) {
        suivant.remove();
      }
    }

    function formaterMo(octets) {
      return (octets / 1024 / 1024).toFixed(1).replace(".", ",") + " Mo";
    }
  }
})();
