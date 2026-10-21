# 3D model viewer files

Served by the Workspace itself, so a page showing a 3D model makes no request to a third party. They are loaded only on a page that shows a glTF binary (GLB) model, and the decoders only when a model is compressed.

| File | Package and version | Licence |
| --- | --- | --- |
| `model-viewer.min.js` | @google/model-viewer 4.1.0 (bundles three.js) | Apache-2.0 |
| `draco/draco_decoder.js`, `draco/draco_decoder.wasm`, `draco/draco_wasm_wrapper.js` | Draco decoder, as shipped in three 0.160.1 `examples/jsm/libs/draco/gltf/` | Apache-2.0 |
| `basis/basis_transcoder.js`, `basis/basis_transcoder.wasm` | Basis Universal transcoder, as shipped in three 0.160.1 `examples/jsm/libs/basis/` | Apache-2.0 |

model-viewer is told to load its Draco and KTX2 decoders from these folders, not from gstatic.com. Meshopt-compressed models are not decoded.

To update a file, copy the new version's file in its place, change the table, and check a GLB on a record page, including a Draco-compressed one.
