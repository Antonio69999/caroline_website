(() => {
  'use strict';

  // Alerte « modifications non enregistrées » : si on ferme la page, ou qu'on la quitte par un lien,
  // alors que le formulaire contient du travail pas encore enregistré, le navigateur demande confirmation.
  //
  // Les formulaires d'EasyAdmin 4 s'appellent ea-new-form / ea-edit-form (ils ont perdu l'ancienne
  // classe ea-crud-form) : c'est ce que cible le sélecteur ci-dessous. Un test (UnsavedWarningTest)
  // le vérifie, pour qu'une mise à jour d'EasyAdmin ne rende pas l'alerte muette sans que personne le voie.
  const FORM_SELECTOR = 'form.ea-new-form, form.ea-edit-form';

  // Un clic sur « Enregistrer » ne doit pas déclencher l'alerte, mais un envoi qui n'aboutit pas
  // (réseau coupé) ne doit pas la désactiver pour toujours.
  const SUBMITTING_MS = 15000;

  function init() {
    const form = document.querySelector(FORM_SELECTOR);
    if (!form) return;

    // Repli si le brouillon automatique (admin_draft.js) n'est pas chargé : on retient qu'une saisie a eu lieu.
    let touched = false;
    let submitting = false;

    const touch = () => {
      touched = true;
    };
    form.addEventListener('input', touch);
    form.addEventListener('change', touch);
    document.addEventListener('ea.collection.item-added', touch);
    document.addEventListener('ea.collection.item-removed', touch);

    form.addEventListener('submit', (event) => {
      // D'autres scripts peuvent retenir l'envoi (champ obligatoire vide…) : on regarde après eux.
      setTimeout(() => {
        if (event.defaultPrevented) return;

        submitting = true;
        setTimeout(() => {
          submitting = false;
        }, SUBMITTING_MS);
      }, 0);
    });

    // Retour arrière vers cette page (elle revient telle qu'elle était, envoi compris) : l'alerte reprend.
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) submitting = false;
    });

    // Le brouillon automatique sait si le formulaire diffère vraiment de celui d'origine
    // (les scripts qui remplissent la page à l'ouverture ne comptent pas comme des modifications).
    const isDirty = () => window.CarotteDraft?.isDirty() ?? touched;

    window.addEventListener('beforeunload', (event) => {
      if (submitting || !isDirty()) return;

      event.preventDefault();
      event.returnValue = '';
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
