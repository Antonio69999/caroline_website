document.addEventListener("DOMContentLoaded", function () {
  const lightbox = document.getElementById("media-lightbox");
  if (!lightbox) {
    return;
  }

  const lightboxImg = lightbox.querySelector("img");
  const closeBtn = lightbox.querySelector(".media-lightbox-close");
  let lastFocusedElement = null;

  function openLightbox(trigger) {
    lastFocusedElement = trigger;
    lightboxImg.src = trigger.src;
    lightboxImg.alt = trigger.alt || "";
    lightbox.hidden = false;
    closeBtn.focus();
  }

  function closeLightbox() {
    lightbox.hidden = true;
    lightboxImg.src = "";
    lastFocusedElement?.focus();
  }

  document.querySelectorAll(".media-library-zoomable").forEach((img) => {
    img.addEventListener("click", () => openLightbox(img));
    img.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        openLightbox(img);
      }
    });
  });

  closeBtn.addEventListener("click", closeLightbox);

  lightbox.addEventListener("click", (event) => {
    if (event.target === lightbox) {
      closeLightbox();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !lightbox.hidden) {
      closeLightbox();
    }
  });
});
