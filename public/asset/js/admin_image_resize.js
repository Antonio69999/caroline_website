document.addEventListener("DOMContentLoaded", function () {
  // Champs d'upload multiple concernés (photos d'un article, nouvelles photos
  // de la médiathèque). Les photos de téléphone font souvent 5-10 Mo : on les
  // réduit ici, dans le navigateur, avant l'envoi.
  const SELECTOR =
    'input[type="file"][name="Article[multipleFiles][]"], input[type="file"][name="Media[newImages][]"]';
  const MAX_DIMENSION = 1920;
  const JPEG_QUALITY = 0.82;
  const RESIZABLE_TYPES = ["image/jpeg", "image/png", "image/webp"];

  // Photos d'iPhone (HEIC/HEIF) : les navigateurs autres que Safari ne savent pas
  // les afficher. On les convertit en JPEG avant l'envoi. La qualité est plus
  // haute que JPEG_QUALITY car l'image est ensuite réduite (donc recompressée).
  const HEIC_TYPES = ["image/heic", "image/heif", "image/heic-sequence", "image/heif-sequence"];
  const HEIC_JPEG_QUALITY = 0.9;
  // Dossier vendor/ à côté de ce script (marche même si le site n'est pas à la racine du domaine).
  const thisScript = document.querySelector('script[src*="admin_image_resize.js"]');
  const HEIC_LIBRARY_URL = new URL(
    "vendor/heic-to.iife.js",
    thisScript?.src || new URL("/asset/js/", window.location.href)
  ).href;

  const SUBMIT_BUTTONS = '.action-save, form.ea-crud-form [type="submit"]';

  let busyCount = 0;

  document.querySelectorAll(SELECTOR).forEach((input) => {
    let latestRun = 0;

    input.addEventListener("change", async function () {
      if (!input.files || input.files.length === 0) {
        return;
      }

      const thisRun = ++latestRun;
      setBusy(input, true);

      try {
        const resizedFiles = await Promise.all(
          Array.from(input.files).map((file) => resizeIfNeeded(file))
        );

        // Si une nouvelle sélection est arrivée entre-temps, c'est elle qui compte.
        if (thisRun !== latestRun) {
          return;
        }

        const dataTransfer = new DataTransfer();
        resizedFiles.forEach((file) => dataTransfer.items.add(file));
        input.files = dataTransfer.files;
      } finally {
        setBusy(input, false);
      }
    });
  });

  // La conversion d'une photo d'iPhone prend quelques secondes : pendant ce
  // temps on bloque l'enregistrement (sinon la photo d'origine partirait).
  function setBusy(input, busy) {
    busyCount += busy ? 1 : -1;

    document.querySelectorAll(SUBMIT_BUTTONS).forEach((button) => {
      button.disabled = busyCount > 0;
    });

    let notice = input.parentElement.querySelector(".image-prepare-notice");
    if (busyCount > 0 && !notice) {
      notice = document.createElement("p");
      notice.className = "image-prepare-notice form-text";
      notice.setAttribute("role", "status");
      notice.textContent = "Préparation des photos en cours… patiente quelques secondes avant d'enregistrer.";
      input.insertAdjacentElement("afterend", notice);
    } else if (busyCount === 0 && notice) {
      notice.remove();
    }
  }

  async function resizeIfNeeded(file) {
    file = await convertHeicIfNeeded(file);

    if (!RESIZABLE_TYPES.includes(file.type)) {
      // Format non pris en charge (ex. HEIC non convertible) :
      // on l'envoie tel quel plutôt que de bloquer l'ajout de la photo.
      return file;
    }

    try {
      const bitmap = await createImageBitmap(file);
      const largestSide = Math.max(bitmap.width, bitmap.height);

      if (largestSide <= MAX_DIMENSION) {
        bitmap.close?.();
        return file;
      }

      const scale = MAX_DIMENSION / largestSide;
      const canvas = document.createElement("canvas");
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);

      const ctx = canvas.getContext("2d");
      ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      bitmap.close?.();

      const blob = await new Promise((resolve) =>
        canvas.toBlob(resolve, "image/jpeg", JPEG_QUALITY)
      );
      if (!blob) {
        return file;
      }

      const newName = file.name.replace(/\.\w+$/, "") + ".jpg";
      return new File([blob], newName, {
        type: "image/jpeg",
        lastModified: Date.now(),
      });
    } catch (error) {
      // Photo non décodable par le navigateur : on ne bloque jamais l'envoi.
      return file;
    }
  }

  function looksLikeHeic(file) {
    const type = file.type.toLowerCase();
    if (HEIC_TYPES.includes(type)) {
      return true;
    }

    // Sans type fiable (fréquent sous Windows), on se fie à l'extension. Un
    // fichier "IMG.HEIC" déjà converti en JPEG par iOS garde son type image/jpeg
    // et ne passe donc pas ici.
    return (type === "" || type === "application/octet-stream") && /\.(heic|heif)$/i.test(file.name);
  }

  async function convertHeicIfNeeded(file) {
    if (!looksLikeHeic(file)) {
      return file;
    }

    try {
      const heicTo = await loadHeicLibrary();
      const blob = await heicTo({ blob: file, type: "image/jpeg", quality: HEIC_JPEG_QUALITY });

      return new File([blob], file.name.replace(/\.\w+$/, "") + ".jpg", {
        type: "image/jpeg",
        lastModified: Date.now(),
      });
    } catch (error) {
      // Conversion impossible : on ne bloque jamais l'envoi, la photo part telle quelle.
      return file;
    }
  }

  // La bibliothèque pèse 3 Mo : on ne la charge que si une photo HEIC est choisie.
  let heicLibraryPromise = null;

  function loadHeicLibrary() {
    if (!heicLibraryPromise) {
      heicLibraryPromise = new Promise((resolve, reject) => {
        if (window.HeicTo) {
          resolve(window.HeicTo);
          return;
        }

        const script = document.createElement("script");
        script.src = HEIC_LIBRARY_URL;
        script.onload = () =>
          window.HeicTo ? resolve(window.HeicTo) : reject(new Error("HeicTo introuvable"));
        script.onerror = () => reject(new Error("Chargement de la conversion HEIC impossible"));
        document.head.appendChild(script);
      }).catch((error) => {
        heicLibraryPromise = null; // on pourra réessayer à la prochaine sélection
        throw error;
      });
    }

    return heicLibraryPromise;
  }
});
