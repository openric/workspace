# Graph viewer files

Served by the Workspace itself, so the public graph page makes no request to a third party. They are loaded only by `discover/graph`. The 3D files load only when a reader chooses 3D.

| File | Package and version | Licence |
| --- | --- | --- |
| `openric-viewer.umd.js` | @openric/viewer 0.4.0 (the openric.org demo viewer) | AGPL-3.0-or-later |
| `cytoscape.min.js` | cytoscape 3.33.2 | MIT |
| `three.min.js` | three 0.160.1 | MIT |
| `three-spritetext.min.js` | three-spritetext 1.10.0 | MIT |
| `3d-force-graph.min.js` | 3d-force-graph 1.73.3, the version openric.org pins (1.80 needs a later three) | MIT |

To update a file, copy the new version's file in its place, change the table, and check 2D and 3D on a graph page.
