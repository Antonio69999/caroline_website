document.addEventListener("DOMContentLoaded", function () {
  // Le champ d'upload multiple de la page "Ajouter des photos" de la médiathèque
  const input = document.querySelector('input[name="Media[newImages][]"]');
  if (!input) {
    return;
  }

  // On ne déplace pas l'input (pour ne pas casser la structure du formulaire
  // Symfony/EasyAdmin) : on se contente d'ajouter une zone de dépôt visuelle
  // juste après, et d'écouter le glisser-déposer sur son conteneur.
  const container = input.closest(".mb-3") || input.parentElement;

  const hint = document.createElement("p");
  hint.className = "media-dropzone-hint";
  hint.textContent = "Astuce : tu peux aussi glisser des photos ici depuis ton dossier.";
  input.insertAdjacentElement("afterend", hint);

  const fileList = document.createElement("ul");
  fileList.className = "media-dropzone-filelist";
  hint.insertAdjacentElement("afterend", fileList);

  function renderFileNames(files) {
    fileList.innerHTML = "";
    Array.from(files).forEach((file) => {
      const li = document.createElement("li");
      li.textContent = file.name;
      fileList.appendChild(li);
    });
  }

  input.addEventListener("change", () => renderFileNames(input.files));

  ["dragenter", "dragover"].forEach((eventName) => {
    container.addEventListener(eventName, (event) => {
      event.preventDefault();
      container.classList.add("media-dropzone-active");
    });
  });

  ["dragleave", "drop"].forEach((eventName) => {
    container.addEventListener(eventName, (event) => {
      event.preventDefault();
      container.classList.remove("media-dropzone-active");
    });
  });

  container.addEventListener("drop", (event) => {
    const files = event.dataTransfer?.files;
    if (files && files.length > 0) {
      input.files = files;
      renderFileNames(files);
    }
  });
});
