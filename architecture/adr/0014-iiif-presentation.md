# ADR 0014: IIIF Presentation 3 from the record: manifests, collections, Change Discovery and Content State

- Status: accepted
- Date: 2026-10-30
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 14 brings IIIF: a IIIF Presentation 3 manifest for each record from its reference copies, a collection for each fonds, a viewer in the public catalogue, following the RiC and IIIF pattern of openric/service #15 (moved to the Workspace as WS-019). Done when the manifests validate and the MCP's image tool can follow them.

Heratio's `ahg-iiif-collection` (8,875 lines, at `492745e31`) has manifests, curated collections, Content Search, Auth Flow 2.0, Change Discovery, Content State, NER annotations, saved viewer workspaces, settings, a validation dashboard and 3D reports. Its manifests paint through a Cantaloupe image server. PSIS's `ahgIiifPlugin` (18,178 lines) builds a richer manifest: language maps, rights, requiredStatement, provider, partOf, navDate, viewingDirection, start, behavior and rendering. Heratio's audio and video canvases are better than PSIS's.

On 30 October 2026 Dr Johan Pieterse chose:

- **The split.** 14a covers manifests, collections, curated collections, the viewer, the RiC and IIIF pattern, Change Discovery, Content State and the IIIF-ready gate. 14b covers Content Search 2.0, Auth Flow 2.0, saved viewer workspaces and the validation dashboard. NER annotations stay in phase 17, and 3D reports in phase 21.
- **No image server.** Manifests paint the reference copy, with no IIIF Image API server.

## Decision

- **RiC describes, IIIF presents.** The record (`rico:RecordResource`) has a manifest. Each of its instantiations (`rico:Instantiation`) that a visitor may see is one canvas, painting the file a visitor may receive.
  - The manifest's `seeAlso` is the record's RiC-O JSON-LD, and its `homepage` is the public page.
  - The record's RiC-O names the manifest with `rdfs:seeAlso`.
  - A record with parts is a Collection of its parts.
  - The manifest describes nothing RiC does not: it is a view of the record, not a record of its own.
- **What a visitor may see decides everything**, as in the API: the public catalogue's view, the published titles, and `InstantiationService::deliverable` for each file. A master is never painted for the public, and is offered under `rendering` only where a visitor may receive it. A withdrawn record's manifest answers 410 (ADR 0013).
- **The manifest** follows PSIS, and the audio and video canvases follow Heratio.
  - Fields: the label and summary in each published language; metadata (reference code, level, dates, extent, creators); and `rights`, the first Creative Commons or RightsStatements.org URI in force, as IIIF requires.
  - Further fields: `requiredStatement` (the holder), the provider (the institution), `partOf` (the unit above), `navDate` (the creation date), and `viewingDirection` (right to left when every script of the material is), plus start, behavior and thumbnail.
  - An image canvas takes its size from the file. A sound or video canvas takes its duration from what exiftool read. A canvas whose duration is not known is left out rather than given Heratio's placeholder of one second.
- **No image service.** A canvas paints the reference copy (JPEG) as a static image, which is valid Presentation 3 and opens in Mirador and Universal Viewer, with no deep-zoom tiles. An image server can be added later without changing the manifests' structure.
- **Curated collections.** Heratio's `iiif_collection` and `iiif_collection_item` are kept as `iiif_collections` and `iiif_collection_items`.
  - An item is a record, another curated collection, or an outside manifest or collection by URI.
  - The behavior and an outside item's type are vocabulary rows (`iiif-behavior`, `iiif-resource-type`).
  - Whoever may publish descriptions may curate them, with no new permission: a curated collection presents published records.
  - A public collection lists only what the public may see.
- **Change Discovery 1.0, level 1.** The stream holds every manifest's latest change. It is made from what the Workspace already keeps: the records' datestamps (as OAI-PMH gives them) for Update, and the deprecation register for Delete. Heratio keeps a table of its own changes; we did not need one.
- **Content State 1.0.** It is copied from Heratio, with no example.com id on the annotation. Decoding accepts only this Workspace's manifests. The viewer page opens a manifest at the canvas a Content State names.
- **Viewer.** The public record page links "Open in Mirador" and the manifest. The viewer page runs Heratio's Mirador on the record's manifest. The per-file viewers are unchanged.
- **The IIIF-ready publish gate** is a file a manifest can paint: an image a browser shows, or sound or video.

## Evidence

`tests/Feature/IiifTest.php` covers:

- Every Presentation 3 requirement the manifest, canvases, painting annotations and collections must meet: http ids, types, language maps, size or duration, painting motivation and target.
- The manifest of the files a visitor may receive, and none of the master's.
- 404 when nothing may be seen.
- The fonds as a collection.
- Curated collections kept, ordered, published and withdrawn.
- Change Discovery with an Update, then a Delete after withdrawal, and 410.
- A Content State round trip and the viewer opening at its canvas.
- RiC-O and the files API naming the manifest.

The presentation-validator's JSON Schema declares no licence, so it is not copied in, and the requirements are checked as the specification states them.

## Consequences

- No deep zoom on large images until an image server or static tiles come; the per-file OpenSeadragon viewer is unchanged.
- Manifests and Change Discovery are made on each request. A large catalogue wants them kept, recorded as `ponytail` in the code.
- Staff see manifests only as the public does. A staff manifest of drafts would come with Auth Flow 2.0 in 14b.
