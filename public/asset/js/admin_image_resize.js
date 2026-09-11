document.addEventListener("DOMContentLoaded", function () {
  // Champs d'upload multiple concernés (photos d'un article, nouvelles photos
  // de la médiathèque). Les photos de téléphone font souvent 5-10 Mo : on les
  // réduit ici, dans le navigateur, avant l'envoi.
  const SELECTOR =
    'input[type="file"][name="Article[multipleFiles][]"], input[type="file"][name="Media[newImages][]"]';
  const MAX_DIMENSION = 1920;
  const JPEG_QUALITY = 0.82;
  const RESIZABLE_TYPES = ["image/jpeg", "image/png", "image/webp"];

  document.querySelectorAll(SELECTOR).forEach((input) => {
    input.addEventListener("change", async function () {
      if (!input.files || input.files.length === 0) {
        return;
      }

      const resizedFiles = await Promise.all(
        Array.from(input.files).map((file) => resizeIfNeeded(file))
      );

      const dataTransfer = new DataTransfer();
      resizedFiles.forEach((file) => dataTransfer.items.add(file));
      input.files = dataTransfer.files;
    });
  });

  async function resizeIfNeeded(file) {
    if (!RESIZABLE_TYPES.includes(file.type)) {
      // Format non pris en charge (ex. HEIC sur certains navigateurs) :
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
});
