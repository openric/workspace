# Modules of the OpenRiC Workspace

What an installation offers, module by module, for a first institutional deployment. Made on 9 November 2026 after the external audit of 8 November (finding F08), which asked for each release to say what is supported, what is experimental and what is off, and for what is not supported to be off unless chosen.

- **Supported**: the archives work V1.0 is for (ROADMAP.md, Releases): accession, arrangement, description, authorities, digital objects, controlled publication, import and export, monitoring. Always on. Under test in every release, and part of what an outside archivist and security tester are asked to sign off.
- **Experimental**: built, tested and copied from Heratio, but beyond V1.0, or depending on an outside service or on demand not yet shown. **Off unless the installation names it** in `WORKSPACE_MODULES` (comma-separated, or `all`). An experimental module that is off is not there: its addresses answer 404, and it leaves the menus and the pages that link to it (`App\Support\Modules`, `App\Http\Middleware\RequireModule`).
- **Own switch**: modules that were already off until something is given, a key, an address or a choice. They keep their own switch.

Turning an experimental module off hides it; it never deletes what was made with it. Its scheduled commands still run and find nothing new to do. The test suite and the accessibility check run with `all`.

## Supported

| Area | What it covers |
|---|---|
| Description | Archival descriptions (record sets, records, record parts) with their tree, versions, dates, identifiers, names and relations; authority records; activities and functions; rules and mandates; places; repositories; instantiations and their files, with viewing copies, IIIF manifests and the viewer; taxonomies and the vocabularies |
| Management | Accessions, donors and agreements; vendors; containers and physical storage; rights; embargoes; records management (file plan, retention and disposal); duplicates; alternative text; the review queue of proposals |
| Workflow | Tasks, pools, queues, publish gates |
| Import and export | EAD 2002, EAD3, EAC-CPF, EAC-F, CSV, Dublin Core, MODS, METS, PREMIS, RiC-O; spreadsheets with column mapping |
| Reports | Overview, data quality, usage statistics, the report builder |
| Public catalogue | Search, records, authority records, places, activities, ways in by theme, place, people, kind and time, the OAI-PMH and OpenRiC API, ResourceSync, sitemaps, static pages and landing pages, the accessibility statement |
| Privacy and jurisdiction | Privacy, security clearance, and the jurisdiction packs an institution chooses in Settings |
| Preservation | The object store (OCFL), fixity, PREMIS events, file formats, packages |
| Administration | Accounts, roles, sign-in settings and two-factor, email, the audit log, operations and monitoring (`/metrics`), display, the dropdown manager, own fields and form templates, label templates, settings |

## Experimental, off unless named

| `WORKSPACE_MODULES` name | What it is | Why it is experimental |
|---|---|---|
| `research` | Reading rooms, researchers and their registration, bookings, reproductions, research projects and the rest of Heratio's ahg-research (phase 19) | The largest module (about 80,000 lines of Heratio), beyond V1.0; no institution has asked for it yet |
| `repatriation` | Repatriation claims, their dialogue, the public register and lodging (phase 17d2) | Needs an institution's policy before a claim is accepted publicly |
| `heritage` | Heritage at risk and displaced heritage (phase 17d3) | Network registers that depend on federation peers |
| `scholarship` | Discoveries, research leads, the languages of the collection, community transcriptions and translations (phase 17d4) | Public contributions need moderation an institution must staff |
| `shop` | Orders, the cart, PayFast (phase 19a) | Taking payment is not an archive's core work |
| `federation` | Federation peers, federated and union search, harvesting (phase 16) | Depends on other installations |
| `archivematica` | The Archivematica connection (phase 15) | Depends on an outside Archivematica |
| `sword` | SWORD deposit (phase 15d) | Used only with Archivematica |
| `dois` | DOIs through DataCite, and the DataCite account in the research services' settings (phase 20c) | Needs a DataCite account; test service first |
| `3d` | 3D model settings, hotspots, saved views and the IIIF 3D manifest (phase 21, v0.0.141); a model file still shows in the viewer without it | The rest of phase 21 is parked |
| `offline-catalogues` | Offline catalogues for a unit and everything inside it (phase 11c) | Recent; needs testing on real devices |
| `webhooks` | Outgoing webhooks (phase 22) | An integration point an institution sets up deliberately |
| `intake` | Watched folders and FTP drops (phase 22a) | Writes to the server's file system from outside |
| `dashboards` | Personal dashboards | Convenience, beyond V1.0 |
| `articles` | Articles, their attachments and comments on the public site (phase 20) | Public comments need moderation |
| `iiif-extras` | IIIF collections, saved viewer layouts and IIIF validation (phase 14) | Beyond the manifest and viewer, which are supported |

## Own switch

| Module | Off until |
|---|---|
| AI: OCR, names, summaries, condition, translation, subjects, search by meaning, the assistant, the AI register (phase 17) | The installation has `AI_GATEWAY_KEY` and the institution switches AI on in Admin, AI; the public assistant also needs "Offer it in the public catalogue" |
| SPARQL and Sparnatural (phase 16e) | `WORKSPACE_SPARQL_URL` names the Fuseki dataset |
| Single sign-on (phase 12b) | An identity provider is added in Admin, Sign-in settings |
| WhatsApp staff notices | `WHATSAPP_ENABLED=true`, once Meta approves the template |
| Jurisdiction packs | Chosen in Settings |
| The virus scanner | On by default (`clamdscan`); `WORKSPACE_VIRUS_SCANNER=` empty turns it off, for development only |

## The demonstration instance

workspace.openric.org runs with `WORKSPACE_MODULES=all`, as a demonstration of everything, on synthetic data. A first institution starts with none named, and names each module it decides to take on.
