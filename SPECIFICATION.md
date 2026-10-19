# Functional requirements

From the OpenRiC Workspace specification and delivery plan of 18 October 2026. Priority: P0 mandatory for that release, P1 important after the core proof, P2 deliberately deferred. The release named is the earliest targeted, not a delivery promise. Every requirement will link to its tests once implementation starts.

## V0.1, proof of concept

Success for V0.1: an archivist, using the interface and no developer or API help, creates a repository and an archival hierarchy, describes a record, links an agent and an activity, records the evidence for that link, uploads a digital file, reviews and publishes what may be published, finds it in public discovery, and exports it through a pinned OpenRiC profile.

| ID | Requirement | Passes when |
|---|---|---|
| WS-001 | A clean install creates an institution, a repository and an operator account. | A fresh installation completes with no Heratio, Qubit or AtoM present, and the restore is documented. |
| WS-002 | Create record set, record and record part; reorder and reparent. | The hierarchy is valid, cycles are refused, persistent identifiers do not change, and every change is logged. |
| WS-003 | Create and link agents, activities and places with typed, directional relations. | Context can be followed both ways; who asserted each link, from what source, and its status are visible. |
| WS-004 | Capture title, dates, scope notes, identifiers and source attribution. | The required descriptive elements can be saved, edited and audited. |
| WS-005 | Interface localisation, and multilingual descriptive values. | The original language is kept; each value has a BCP 47 tag and its own state; fallback is explicit; values round-trip. |
| WS-006 | Provenance of each assertion about a relation, and of its changes. | Who, when, from what source, on what basis and with what review status can be reconstructed; nothing is silently replaced. |
| WS-007 | Attach one or more files with MIME type, SHA-256, rights and availability. | Fixity is checked and files are delivered under control; no direct URL bypasses access rules. |
| WS-008 | Draft, review, publish and withdraw records and their language values. | Unpublished content cannot be reached through any public channel. |
| WS-009 | Browse the hierarchy, search published descriptions, follow the graph context. | A user finds the demonstration record and its agent and activity from the interface. |
| WS-010 | Export a pinned OpenRiC profile from the canonical model. | A published independent fixture passes; identifiers and language tags survive the export. |
| WS-011 | Basic roles, authorisation, backup and restore. | Default-deny negative tests pass; a restored instance contains every demonstration entity and file. |
| WS-012 | An archivist-led usability demonstration and an independent installer. | The archivist completes the agreed test without code edits or API tools. |
| WS-013 | An early check of migration from Heratio on a difficult, representative sample. | Reconciliation lists what was kept, changed and lost; nothing is lost silently. |

## V1.0, operational

| ID | Requirement | Passes when |
|---|---|---|
| WS-014 | Accession register, basic arrangement and descriptive workflow. | A pilot archivist can register holdings and document custody and context. |
| WS-015 | CSV, EAD and RiC input mapping with exception reports, dry runs and reconciliation. | Every rejected or ambiguous field is listed; imports can be repeated. |
| WS-016 | Search and reporting for everyday work. | Indexing respects access rules; acceptance queries and usability benchmarks are met. |
| WS-017 | Monitoring, update and restore procedures, and institutional permissions. | Security, accessibility and disaster recovery are signed off. |

## V2.0 and later (P2)

| ID | Requirement | Passes when |
|---|---|---|
| WS-018 | Advanced donor, accession and appraisal work, batch changes, integration protocols. | A separate product discovery, costing and profile-compatibility sign-off. |
| WS-019 | Federation, semantic exploration, IIIF and preservation connectors, AI assistance. | Modules with consent, human review and traceable provenance. |

## Not in V0.1

Enterprise records management, museum and library functions, advanced appraisal and the accession lifecycle, mass import, a full preservation repository, semantic federation, AI, and any claim of production readiness.

## Hard constraints

- The OpenRiC specification is implementation-neutral; the Workspace consumes published, versioned profiles.
- Every entity has a stable UUID and a persistent URI; RiC entities and relations are typed; the hierarchy is a navigational view, not the whole model.
- A clean deployment needs only the Workspace, its database, its file storage and a worker; no shared Heratio or Qubit data, credentials, queues, endpoints or services.
- Originals are immutable: translations and inferences never overwrite the original description, and the provenance of each assertion survives edits, import and export.
- Default deny: unpublished, embargoed, restricted and draft language values do not leak through the interface, API, file URLs, exports, search, caches or graph projections.
- One canonical transactional store; RDF and search are rebuildable projections, and neither is required for V0.1.
