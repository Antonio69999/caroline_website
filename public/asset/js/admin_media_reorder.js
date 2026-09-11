document.addEventListener("DOMContentLoaded", function () {
  const gallery = document.querySelector(".media-gallery-sortable");

  if (!gallery) {
    return;
  }

  // Ajoute (ou met à jour) les flèches ▲▼ sur chaque photo : une alternative
  // accessible au glisser-déposer pour qui ne peut pas utiliser la souris.
  function updateMoveButtons() {
    const items = gallery.querySelectorAll(".media-gallery-item");

    items.forEach((item, index) => {
      let controls = item.querySelector(".media-gallery-move-buttons");
      if (!controls) {
        controls = document.createElement("div");
        controls.className = "media-gallery-move-buttons";
        item.appendChild(controls);
      }
      controls.innerHTML = "";

      const legende =
        item.querySelector(".media-gallery-caption")?.textContent.trim() ||
        "cette photo";
      const isFirst = index === 0;
      const isLast = index === items.length - 1;

      const upBtn = document.createElement("button");
      upBtn.type = "button";
      upBtn.className = "media-gallery-move-btn";
      upBtn.dataset.direction = "up";
      upBtn.textContent = "▲";
      upBtn.setAttribute("aria-label", `Monter « ${legende} »`);
      upBtn.disabled = isFirst;

      const downBtn = document.createElement("button");
      downBtn.type = "button";
      downBtn.className = "media-gallery-move-btn";
      downBtn.dataset.direction = "down";
      downBtn.textContent = "▼";
      downBtn.setAttribute("aria-label", `Descendre « ${legende} »`);
      downBtn.disabled = isLast;

      controls.append(upBtn, downBtn);
    });
  }

  function persistOrder() {
    updateMoveButtons();

    const items = gallery.querySelectorAll(".media-gallery-item");
    const orderedIds = [];

    items.forEach((item) => {
      const mediaId = item.getAttribute("data-id");
      if (mediaId) {
        orderedIds.push(mediaId);
      }
    });

    fetch("/admin/media/reorder", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ orderedIds: orderedIds }),
    })
      .then((response) => {
        if (response.ok) {
          showMediaGalleryToast("✅ Ordre des photos sauvegardé !", "success");
        } else {
          showMediaGalleryToast("❌ Oups, une erreur est survenue.", "danger");
        }
      })
      .catch(function () {
        showMediaGalleryToast("❌ Impossible de joindre le serveur.", "danger");
      });
  }

  updateMoveButtons();

  new Sortable(gallery, {
    animation: 150,
    ghostClass: "bg-light",
    onEnd: persistOrder,
  });

  gallery.addEventListener("click", function (event) {
    const button = event.target.closest(".media-gallery-move-btn");
    if (!button || button.disabled) {
      return;
    }

    const item = button.closest(".media-gallery-item");
    const direction = button.getAttribute("data-direction");
    const sibling =
      direction === "up" ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling) {
      return;
    }

    if (direction === "up") {
      gallery.insertBefore(item, sibling);
    } else {
      gallery.insertBefore(sibling, item);
    }

    persistOrder();

    const sameButton = item.querySelector(
      `.media-gallery-move-btn[data-direction="${direction}"]`
    );
    const fallbackButton = item.querySelector(".media-gallery-move-btn");
    (sameButton && !sameButton.disabled ? sameButton : fallbackButton)?.focus();
  });

  function showMediaGalleryToast(message, type) {
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
