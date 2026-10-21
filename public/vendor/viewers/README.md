# Viewers reused from Heratio

`resources/views/description/_viewer.blade.php` shows each digital file in the viewer that suits it. It is Heratio's digital object viewer (`ahg-information-object-manage`, `partials/_digital-object-viewer.blade.php`), with these files copied from Heratio's `public/vendor` to the same paths here. They are served by the Workspace, so a page calls no third party.

| Path under `public/vendor/` | What | Licence |
| --- | --- | --- |
| `openseadragon/6.0.2/` | OpenSeadragon 6.0.2, its images, and Heratio's filtering, scale-bar and magnifier plugins | BSD-3-Clause (plugins: AHG, AGPL-3.0-or-later) |
| `ahg-theme-b5/js/ahg-iiif-viewer.js` | Heratio's image viewer: deep zoom, Mirador, plain image, full screen; copied unchanged | AGPL-3.0-or-later |
| `ahg-theme-b5/js/vendor/mirador/` | Mirador (IIIF viewer), Heratio's bundle | Apache-2.0 |
| `pdfjs/3.11.174/` | pdf.js 3.11.174 and its worker | Apache-2.0 |
| `plyr/` | Plyr media player | MIT |
| `wavesurfer/` | WaveSurfer.js audio waveform | BSD-3-Clause |
| `three/0.160.0/` | three.js 0.160.0 with OrbitControls, OBJLoader and STLLoader only | MIT |

Kept apart from Heratio:

- **No IIIF image server.** The Workspace has none. Its file URLs have no file extension, so Heratio's script opens OpenSeadragon on the image itself and Mirador builds its manifest in the browser. Heratio's check of Cantaloupe's `info.json` is left out, because with no server it would always switch to the plain image.
- **PDFs are drawn with pdf.js.** Heratio uses an iframe of the browser's own viewer, which a sandboxed file response cannot fill.
- **GLB models keep model-viewer.** That is `public/vendor/model-viewer/`; see its README.

To update, copy the newer files from Heratio's `public/vendor`, change this table, and check an image, a PDF, an audio file and an OBJ model on a record page.
