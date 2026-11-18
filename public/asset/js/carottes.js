/*
 * Carottes cachées (templates/components/carottes.html.twig, public/asset/css/carottes.css).
 *
 * Le serveur écrit chaque carotte cachée (hidden) avec ses réglages en attributs data-*. Ce script :
 *  - la montre après son délai (data-delai, en secondes passées sur la page), sauf si elle a déjà été
 *    trouvée sur ce navigateur ;
 *  - au clic, retient qu'elle est trouvée, la fait disparaître et ouvre son image (taille et place
 *    venues des attributs) ; un clic n'importe où ou Échap referme l'image.
 *
 * « Trouvée » est retenu carotte par carotte (localStorage, clé carottesTrouvees) : trouver l'une
 * ne cache pas les autres. Aperçu depuis l'admin (data-forcee="1") : la carotte s'affiche tout de
 * suite, même déjà trouvée, et un clic ne la marque pas comme trouvée.
 */
(function () {
  "use strict";

  const CLE = "carottesTrouvees";
  const DELAI_APERCU = 600; // ms

  const fenetre = document.getElementById("carotteFenetre");
  const image = fenetre ? fenetre.querySelector("img") : null;
  const carottes = Array.from(document.querySelectorAll("#carottes .carotte"));

  if (!fenetre || !image || carottes.length === 0) {
    return;
  }

  // Le stockage du navigateur peut être indisponible (navigation privée…) : on ne casse jamais la page pour ça.
  function lireTrouvees() {
    try {
      const liste = JSON.parse(window.localStorage.getItem(CLE) || "[]");
      return Array.isArray(liste) ? liste.map(String) : [];
    } catch (e) {
      return [];
    }
  }

  function retenir(id) {
    try {
      const liste = lireTrouvees();
      if (!liste.includes(id)) {
        liste.push(id);
        window.localStorage.setItem(CLE, JSON.stringify(liste));
      }
    } catch (e) {
      // sans stockage, la carotte fonctionne quand même sur la page courante
    }
  }

  function ouvrir(carotte) {
    const data = carotte.dataset;

    image.alt = data.description || "";
    image.src = data.image;
    fenetre.dataset.taille = data.imageTaille;
    fenetre.dataset.ligne = data.imageLigne;
    fenetre.dataset.colonne = data.imageColonne;
    fenetre.hidden = false;
    fenetre.focus({ preventScroll: true });
  }

  function fermer() {
    fenetre.hidden = true;
  }

  fenetre.addEventListener("click", fermer);
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !fenetre.hidden) {
      fermer();
    }
  });

  const trouvees = lireTrouvees();

  carottes.forEach(function (carotte) {
    const id = carotte.dataset.carotte;
    const apercu = carotte.dataset.forcee === "1";

    if (!apercu && trouvees.includes(id)) {
      carotte.remove();
      return;
    }

    carotte.addEventListener("click", function () {
      if (!apercu) {
        retenir(id);
      }
      carotte.classList.add("carotte--trouvee");
      ouvrir(carotte);
      setTimeout(function () {
        carotte.hidden = true;
      }, 400);
    });

    const delai = apercu ? DELAI_APERCU : (parseInt(carotte.dataset.delai, 10) || 0) * 1000;
    if (delai <= 0) {
      carotte.hidden = false;
    } else {
      setTimeout(function () {
        carotte.hidden = false;
      }, delai);
    }
  });
})();
