document.addEventListener("DOMContentLoaded", function () {
  const table = document.querySelector("table.datagrid");
  const originalTbody = table?.querySelector("tbody");

  if (!table || !originalTbody) {
    return;
  }

  const columnCount = table.querySelectorAll("thead th").length || 1;
  const rows = Array.from(originalTbody.querySelectorAll("tr[data-id]"));

  if (rows.length === 0) {
    return;
  }

  // Les articles arrivent déjà triés par catégorie puis par position (voir
  // ArticleCrudController::configureCrud) : on regroupe donc les lignes
  // consécutives par catégorie, chaque groupe devenant son propre <tbody>
  // avec son propre glisser-déposer. Sans "group" partagé entre les
  // instances Sortable, il est impossible de glisser un article d'une
  // catégorie vers une autre : l'ordre ne peut être changé qu'à l'intérieur
  // d'une même catégorie, là où il a vraiment un sens.
  const groups = [];
  let currentLabel = null;

  rows.forEach((row) => {
    const label =
      row.querySelector('td[data-column="categorie"]')?.textContent.trim() ||
      "Sans catégorie";

    if (label !== currentLabel) {
      currentLabel = label;
      groups.push({ label, rows: [] });
    }

    groups[groups.length - 1].rows.push(row);
  });

  groups.forEach((group) => {
    const headerTbody = document.createElement("tbody");
    const headerRow = document.createElement("tr");
    headerRow.className = "article-category-header-row";
    const headerCell = document.createElement("td");
    headerCell.colSpan = columnCount;
    headerCell.textContent = "📁 " + group.label;
    headerRow.appendChild(headerCell);
    headerTbody.appendChild(headerRow);

    const rowsTbody = document.createElement("tbody");
    rowsTbody.className = "article-category-rows";
    group.rows.forEach((row) => rowsTbody.appendChild(row));

    table.insertBefore(headerTbody, originalTbody);
    table.insertBefore(rowsTbody, originalTbody);
    group.tbody = rowsTbody;
  });

  originalTbody.remove();

  // --- La fonction qui "maquille" les numéros et pose les flèches ▲▼ ---
  function updateVisualPositions(tbody) {
    const groupRows = tbody.querySelectorAll("tr");
    groupRows.forEach((row, index) => {
      const positionCell = row.querySelector('td[data-column="position"]');
      if (!positionCell) {
        return;
      }

      // La numérotation repart de 1 à chaque catégorie : c'est cet ordre,
      // propre à la catégorie, qui compte (voir ArticleRepository::findWithPosition)
      const visualNumber = index + 1;
      const titre =
        row.querySelector('td[data-column="titre"]')?.textContent.trim() ||
        "cet article";
      const isFirst = index === 0;
      const isLast = index === groupRows.length - 1;

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
      upBtn.setAttribute("aria-label", `Monter « ${titre} » dans sa catégorie`);
      upBtn.disabled = isFirst;

      const downBtn = document.createElement("button");
      downBtn.type = "button";
      downBtn.className = "position-move-btn";
      downBtn.dataset.direction = "down";
      downBtn.textContent = "▼";
      downBtn.setAttribute("aria-label", `Descendre « ${titre} » dans sa catégorie`);
      downBtn.disabled = isLast;

      controls.append(upBtn, downBtn);
      positionCell.appendChild(controls);
    });
  }

  function persistOrder(tbody) {
    updateVisualPositions(tbody);

    const orderedIds = Array.from(tbody.querySelectorAll("tr")).map((row) =>
      row.getAttribute("data-id")
    );

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
  }

  groups.forEach((group) => {
    const tbody = group.tbody;

    // 1. On applique le nouveau design dès le chargement de la page
    updateVisualPositions(tbody);

    // 2. On lance la magie du Drag & Drop (indépendant par catégorie)
    new Sortable(tbody, {
      animation: 150,
      ghostClass: "bg-light",
      onEnd: () => persistOrder(tbody),
    });

    // 3. Les flèches ▲▼ : même résultat que le glisser-déposer, au clavier
    tbody.addEventListener("click", function (event) {
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
        tbody.insertBefore(row, sibling);
      } else {
        tbody.insertBefore(sibling, row);
      }

      persistOrder(tbody);

      // On garde le focus sur la ligne qu'on vient de déplacer plutôt que
      // de le perdre dans la nature après la reconstruction des boutons.
      const sameButton = row.querySelector(
        `.position-move-btn[data-direction="${direction}"]`
      );
      const fallbackButton = row.querySelector(".position-move-btn");
      (sameButton && !sameButton.disabled ? sameButton : fallbackButton)?.focus();
    });
  });

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
