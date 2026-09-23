document.addEventListener("DOMContentLoaded", function () {
  const tableBody = document.querySelector("table.datagrid tbody");

  if (tableBody) {
    // --- La fonction qui "maquille" les numéros et pose les flèches ▲▼ ---
    function updateVisualPositions() {
      const rows = tableBody.querySelectorAll("tr");
      rows.forEach((row, index) => {
        const positionCell = row.querySelector('td[data-column="position"]');
        if (!positionCell) {
          return;
        }

        // Peu de catégories en pratique et tout tient sur une seule page :
        // le numéro visuel est simplement la position dans la liste affichée
        const visualNumber = index + 1;
        const titre =
          row.querySelector('td[data-column="titre"]')?.textContent.trim() ||
          "cette catégorie";
        const isFirst = index === 0;
        const isLast = index === rows.length - 1;

        // On remplace le texte moche par un beau badge bleu typé EasyAdmin
        positionCell.innerHTML = `<span class="badge badge-primary" style="font-size: 14px; padding: 4px 8px; border-radius: 4px;">${visualNumber}</span>`;

        // Flèches clavier : une alternative accessible au glisser-déposer,
        // pour qui ne peut pas utiliser la souris (voir WCAG 2.5.7).
        const controls = document.createElement("span");
        controls.className = "position-move-buttons";

        const upBtn = document.createElement("button");
        upBtn.type = "button";
        upBtn.className = "position-move-btn";
        upBtn.dataset.direction = "up";
        upBtn.textContent = "▲";
        upBtn.setAttribute("aria-label", `Monter « ${titre} »`);
        upBtn.disabled = isFirst;

        const downBtn = document.createElement("button");
        downBtn.type = "button";
        downBtn.className = "position-move-btn";
        downBtn.dataset.direction = "down";
        downBtn.textContent = "▼";
        downBtn.setAttribute("aria-label", `Descendre « ${titre} »`);
        downBtn.disabled = isLast;

        controls.append(upBtn, downBtn);
        positionCell.appendChild(controls);
      });
    }

    function persistOrder() {
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
      fetch("/admin/categorie/reorder", {
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
    }

    // 1. On applique le nouveau design dès le chargement de la page
    updateVisualPositions();

    // 2. On lance la magie du Drag & Drop
    new Sortable(tableBody, {
      animation: 150,
      ghostClass: "bg-light",
      onEnd: persistOrder,
    });

    // 3. Les flèches ▲▼ : même résultat que le glisser-déposer, au clavier
    tableBody.addEventListener("click", function (event) {
      const button = event.target.closest(".position-move-btn");
      if (!button || button.disabled) {
        return;
      }

      const row = button.closest("tr");
      const direction = button.getAttribute("data-direction");
      const sibling =
        direction === "up"
          ? row.previousElementSibling
          : row.nextElementSibling;
      if (!sibling) {
        return;
      }

      if (direction === "up") {
        tableBody.insertBefore(row, sibling);
      } else {
        tableBody.insertBefore(sibling, row);
      }

      persistOrder();

      // On garde le focus sur la ligne qu'on vient de déplacer plutôt que
      // de le perdre dans la nature après la reconstruction des boutons.
      const sameButton = row.querySelector(
        `.position-move-btn[data-direction="${direction}"]`
      );
      const fallbackButton = row.querySelector(".position-move-btn");
      (sameButton && !sameButton.disabled ? sameButton : fallbackButton)?.focus();
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
