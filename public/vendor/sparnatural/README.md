# Sparnatural

`resources/views/discover/explore.blade.php` embeds Sparnatural, the visual SPARQL query builder of Sparna (sparna-git/Sparnatural), so a visitor explores the published RiC-O graph without writing SPARQL (roadmap phase 16e, ADR 0023). It is served by the Workspace, so the page calls no third party.

| Path under `public/vendor/` | What | Licence |
| --- | --- | --- |
| `sparnatural/14.0.0/sparnatural.js`, `sparnatural.css` | Sparnatural 14.0.0, the built files of the npm package `sparnatural`, unchanged (tarball SHA-1 `113cc57c8f0367755b9af9209cea05e41c8dd8bf`) | LGPL-3.0 (`LICENSE.md`); the bundled libraries in `sparnatural.js.LICENSE.txt` |

Its configuration is not written by hand: `/sparnatural/config.ttl` is made by `App\Federation\SparnaturalConfig` from the pinned OpenRiC shapes in `resources/shapes/openric-0.56.0/` and the Workspace's relation types.

To update, download the newer npm tarball into a fresh folder, check its checksum against the registry's, copy its `dist/` files into a new version folder, change the two paths in the explore page and this table, and build a query on `/discover/explore`.
