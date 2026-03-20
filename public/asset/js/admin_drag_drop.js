document.addEventListener("DOMContentLoaded", function () {
  const tableBody = document.querySelector("table.datagrid tbody");

  if (tableBody) {
    // --- NOUVEAUTÉ : La fonction qui "maquille" les numéros ---
    function updateVisualPositions() {
      // On regarde sur quelle page on est (utile si tu as plus de 20 articles)
      const urlParams = new URLSearchParams(window.location.search);
      const page = parseInt(urlParams.get("page")) || 1;
      const itemsPerPage = 20; // Correspond au setPaginatorPageSize(20) qu'on a mis côté PHP
      const startIndex = (page - 1) * itemsPerPage;

      const rows = tableBody.querySelectorAll("tr");
      rows.forEach((row, index) => {
        const positionCell = row.querySelector('td[data-column="position"]');
        if (positionCell) {
          // On calcule le vrai numéro visuel (1, 2, 3...)
          const visualNumber = startIndex + index + 1;
          // On remplace le texte moche par un beau badge bleu typé EasyAdmin
          positionCell.innerHTML = `<span class="badge badge-primary" style="font-size: 14px; padding: 4px 8px; border-radius: 4px;">${visualNumber}</span>`;
        }
      });
    }

    // 1. On applique le nouveau design dès le chargement de la page
    updateVisualPositions();

    // 2. On lance la magie du Drag & Drop
    new Sortable(tableBody, {
      animation: 150,
      ghostClass: "bg-light",

      onEnd: function (evt) {
        // Dès qu'on lâche la ligne, on recalcule les numéros visuels 1, 2, 3 immédiatement
        updateVisualPositions();

        const rows = tableBody.querySelectorAll("tr");
        const orderedIds = [];

        rows.forEach((row) => {
          const entityId = row.getAttribute("data-id");
          if (entityId) {
            orderedIds.push(entityId);
          }
        });

        // On sauvegarde en silence
        fetch("/admin/article/reorder", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify({ orderedIds: orderedIds }),
        })
          .then((response) => {
            if (response.ok) {
              showToast("✅ Ordre sauvegardé !", "success");
            } else {
              showToast("❌ Oups, une erreur est survenue.", "danger");
            }
          })
          .catch((error) => {
            showToast("❌ Impossible de joindre le serveur.", "danger");
          });
      },
    });
  }

  // Fonction d'affichage du Toast (inchangée)
  function showToast(message, type) {
    const toast = document.createElement("div");
    toast.className = `alert alert-${type} alert-dismissible fade show`;
    toast.style.position = "fixed";
    toast.style.bottom = "20px";
    toast.style.right = "20px";
    toast.style.zIndex = "9999";
    toast.style.boxShadow = "0 4px 12px rgba(0,0,0,0.15)";
    toast.style.transition = "opacity 0.3s ease";
    toast.innerText = message;
    document.body.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = "0";
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }
});
