# Bibliothèques tierces (admin)

## heic-to.iife.js

- Projet : [heic-to](https://github.com/hoppergee/heic-to) 1.6.6 (libheif 1.23.6 compilé en WebAssembly) – licence LGPL-3.0, voir `heic-to.LICENSE.txt`.
- Fichier : `dist/iife/heic-to.js` du paquet npm `heic-to@1.6.6`, copié tel quel (expose `window.HeicTo`).
- Rôle : convertir en JPEG, dans le navigateur, les photos HEIC d'iPhone avant leur envoi. Chargé à la demande par `../admin_image_resize.js`, uniquement quand un fichier HEIC est choisi.
- Mise à jour : `npm pack heic-to@<version>`, puis remplacer le fichier et ce numéro de version.
