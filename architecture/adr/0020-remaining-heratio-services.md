# ADR 0020: The remaining Heratio services, intake first

- Status: accepted
- Date: 2026-11-03
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 22 takes whatever the parity register still lists as open: 17 Heratio packages, about 120,000 lines. Ingest, scanning, FTP and SharePoint intake, labels, version history, duplicates, custom fields, form templates, GraphQL, the rest of the API, GIS, media streaming, vendors, the marketplace, C2PA and research data management. On 3 November 2026 Dr Johan Pieterse chose:

- **1B.** Build intake, description quality, interfaces and vendors. C2PA and research data management (`ahg-c2pa`, `ahg-rdm`) move to phase 17, because both rest on the AI services. The marketplace (`ahg-marketplace`) is not carried: selling items is not an archive's work, and the Workspace covers archives only up to V1.0.
- **2B.** FTP drops and watched folders now. SharePoint intake waits for a pilot with a Microsoft tenant to test it against.
- The order 22a intake, 22b description quality (version history, duplicates, custom fields, forms), 22c interfaces (GraphQL, the rest of the API, GIS, media streaming), 22d vendors, each released on its own.

## Decision (22a: intake)

**The import grows; there is no second ingest pipeline.** PSIS's `ahgIngestPlugin` (12,800 lines) runs its own six-step wizard beside AtoM's import. The Workspace already had an import with a dry run, a report and a commit, keyed to its source so a second run updates rather than duplicates. 22a adds PSIS's other steps to that one import:

- **Spreadsheets.** An Excel or OpenDocument file is read as the CSV of its first sheet.
- **Column mapping.** A file not in AtoM's columns is mapped before its dry run. Each heading is offered the column it most likely is, with how sure the match is, from PSIS's own list of aliases. A column can be left out, given a value for empty cells, or transformed. Mappings can be kept by name and used again. A file in AtoM's columns goes straight to its dry run, as before, and can still be mapped from its report.
- **ZIP bundles.** A ZIP of one CSV or spreadsheet and the files its rows name in `digitalObjectPath` brings those files in as instantiations, checked against a row's checksum when it gives one. PSIS unpacked the ZIP on the server; here a file is read from the ZIP only when it is kept, and a name that climbs out of the ZIP is refused.
- **Classification.** An import of descriptions can classify what it creates at a chosen security level (phase 18c), as PSIS's ingest could.
- **Undo.** A committed import can be undone. Each entry it created is deleted as any entity is: through the deletion rules, its IRI kept and answering as deprecated, children first, and the files it kept before the records that carry them. An entry anyone changed after the commit is kept and named in the report, so undoing never loses later work. That covers its values, names, dates, relations and instantiations. What the import only updated is not put back; the audit log keeps what it was. The audit log's sequence decides what came after the commit, not a clock. PSIS rolled back; a Workspace import commits in parts, so it is undone afterwards instead.
- **A BagIt bag.** Any committed import downloads as a BagIt 1.0 bag (RFC 8493): the file as uploaded, the files it kept, and what became of each entry with its IRI, all with SHA-256 manifests. This replaces PSIS's OAIS SIP and DIP packaging with the form an SIP is usually exchanged in.
- **Grid entry.** Rows typed or pasted from a spreadsheet under a record set go through the same dry run and report.

**Watched folders, which also carry FTP.** After PSIS's `ingest_watch_folder` and Heratio's `ahg-scan`. Every watched folder lies under one intake root set by whoever runs the server (`WORKSPACE_INTAKE_ROOT`), so a folder named in the Workspace cannot reach the rest of the server. Every minute each enabled folder is read, and a file still being written waits for the next pass:

- a CSV, spreadsheet, ZIP or EAD file is imported with the folder's settings and mapping, and committed when its dry run refuses nothing (if the folder says so);
- a BagIt bag is checked against its manifest and refused whole if any file differs;
- any other file becomes a record, together with the files of the same stem (`MB-1902_p1.png`, `MB-1902_p2.png`) and the `<heratioScan>` sidecar beside them, so scanners set up for Heratio work unchanged.

What was taken in moves to `.processed/`, what failed to `.failed/` with the reason beside it, and every file is logged. Heratio's FTP upload pushed files from the browser to an FTP server for AtoM to fetch. The Workspace keeps files in its own store, so FTP becomes an FTP or SFTP account kept to a watched folder.

**The scan API.** After Heratio's scan API: a capture station posts an item's files, with its description or a sidecar, using an API key with the write scope, and a record is made of them as a watched folder would make it. Heratio's session tokens are not needed, because one request carries the whole item.

**Labels.** After Heratio's `ahg-label`: templates of a sheet's size and grid, and labels for a record, what is inside it, or a container, each showing title, identifier and repository as the template asks. Each label has a Code 128 barcode of its identifier, reference code, container barcode or IRI, and a QR code of its page or identifier. Heratio fetched its barcodes from an outside service, sending it every identifier it printed. The Workspace draws Code 128 itself, to ISO/IEC 15417, switching to the compact digit set for runs of digits. QR codes come from the BaconQrCode library Heratio uses.

## Not carried in 22a

- SharePoint intake (2B).
- PSIS's processing options on ingest (OCR, named entities, summaries, translation, face detection), which are phase 17.
- PSIS's library (ONIX) and museum sectors.
- Heratio's sector routing, rights enforcement and web archiving in `ahg-scan`.
- Session tokens on the scan API.
- A chunked browser upload for very large files, which go through a watched folder instead.

## Evidence

- `tests/Feature/IngestTest.php`: a spreadsheet in a ZIP mapped, its file kept, classified, bagged and checked against every SHA-256, then undone with the changed entry kept; grid entry.
- `tests/Feature/IntakeTest.php`: a watched folder taking in paged scans with a sidecar, a table, a good bag, and refusing a tampered bag; a folder outside the root refused; the scan API.
- `tests/Feature/LabelsTest.php`: Code 128 symbols and check worked by hand (the same strings decoded by `zbarimg` when written), templates that must fit, sheets for records and containers, refused to another institution.
- `tests/Feature/ImportTest.php` unchanged: a file in AtoM's columns is not sent to mapping.

## Decision (22b: description quality), 3 November 2026

**Version history.** After Heratio's `ahg-version-control`, for units of description and authority records.

- **What a version holds.** The whole of what the entity says: values, names, dates, identifiers, own fields and relations, and where it sits.
- **When one is written.** Heratio wrote a version when the entity's own row was saved. In the Workspace an edit changes values and names, not that row. So any write to the entity, or to anything of it, marks it, exactly as the search index is marked, and one version is written per entity when the request, command or job ends, unless nothing changed.
- **Comparing and restoring.** Any two versions compare field by field. Restoring puts back the values, names and own fields a version had, each value and name under its own id and IRI. What changes goes back to draft for review, as an edit does. Dates, identifiers and relations are shown but not restored, as in Heratio, and the restore is itself a new version.
- `workspace:versions:capture` takes a first version of everything that has none.

**Duplicate descriptions.** After Heratio's `ahg-dedupe`, for archival descriptions; authority records and places already had their own duplicates and merge.

- **Finding them.** Rules are rows: similar titles, the same or similar identifiers, the same date and creator, the same master file. Each has a threshold and Heratio's defaults. A weekly or requested scan writes each pair once, with its score and why. A pair dismissed stays dismissed.
- **Merging.** Merging keeps one unit and gives it the other's units, relations, the names and values it lacks, dates and identifiers. The other unit is then deleted as merged, its IRI answering what replaced it, as a merged agent's does. A declared record is not merged away.
- **Instantiations.** An instantiation stays with the unit it instantiates (the database holds to it). So the unit kept gets its own instantiation of each of the other's, from the same master file, and the store keeps one copy of the bytes.
- **Changed from Heratio:** titles are compared within a repository.

**The institution's own fields.** After Heratio's `ahg-custom-fields`, for records, authority records, repositories, accessions and activities.

- **Definitions are rows,** with Heratio's switches: required, repeatable, searchable, shown to the public, on the edit form, exported, and a check (a regular expression). There are eight kinds of value. A dropdown draws from one of the institution's SKOS vocabularies rather than a separate dropdown manager, so its terms have IRIs.
- **Display, search, export and import.** Values are shown on the entity's page (and publicly where marked), searched where marked (the public only what it sees), and kept in versions. They are exported in the archival description CSV as `custom_<key>` columns and read back by the CSV import.
- **RDF.** RiC-O has no term for an institution's own field, so an RDF export lists them as not carried.

**Form templates.** After Heratio's `ahg-forms`.

- **What a template is.** A list of the description elements of its kind and the institution's own fields, each with its label, help, default, whether it is required, and its section, in order.
- **Where it applies.** Where an assignment says, by repository, level or collection (a collection's covering what is inside it), the lowest priority number first, then the kind's default.
- **Describing through one** writes through the same services as the full form, so each value keeps its element's RiC term.
- **Changed from Heratio:** a field maps to an element or an own field, not to any table column.

### Not carried in 22b

- Heratio's version links injected into other packages' pages (the Workspace links its own).
- Its dedupe report charts and its separate merge log table (the pair keeps what moved, and the audit log the rest).
- Its form builder's drag-and-drop and its field types that are layout only (heading, divider), since sections do that work.
- Form template export and import between installations.

### Evidence (22b)

- `tests/Feature/VersionsTest.php`: one version per change and none for no change; comparison; restore with ids kept; refusal; first versions captured.
- `tests/Feature/DuplicatesTest.php`: the default rules finding a pair once; comparison; the merge with what moved and the deprecated IRI; a dismissed pair staying dismissed.
- `tests/Feature/CustomFieldsTest.php`: definitions and values checked; public and staff display and search; versions; the CSV export and its import back; a form template that applies inside its collection and describes through it.

## Decision (22c: interfaces), 3 November 2026

Dr Johan Pieterse chose 1A and 2A on 3 November 2026.

**1A: the API still never writes directly (ADR 0007 kept).** Everything 22c adds to the API reads, and only what the public may read, from the same public catalogue the rest of the API uses. A system that wants to change something sends proposals, as before.

**Webhooks.** After Heratio's `ahg-api` WebhookService, kept as rows per institution and managed by who may manage the institution.

- **What is sent.** The event (created, updated, deleted, published, withdrawn), the kind of entity, its id, its persistent IRI, its API address and when it happened. Never what the entity says: the receiver reads that through the API with its own key, so clearance, embargoes and redaction hold. Heratio sent the entity's data.
- **When.** An entity is marked as the search index and versions mark it, and each webhook is told once per entity and event when the request, command or job ends. A publish or withdrawal is told as that, not also as a change; a create is not also told as a change.
- **Signing and retries.** Heratio's HMAC-SHA256 of the body with the webhook's secret, in `X-OpenRiC-Signature`; retries after 1, 2, 4, 8 and 16 minutes (`workspace:webhooks:retry` every minute); after five failed deliveries the webhook is left alone until saved again. The secret is shown once, when it is made.
- **Where it may post.** Only a public http or https address, never this server or a private network, checked when it is saved and again at every delivery; redirects are not followed. Heratio did not check.

**Read surfaces.**

- **OpenAPI 3.1** at `/api/ric/v1/openapi.json`, built from the API's own routes each time it is asked for, so it never describes an address the API does not have.
- **Sitemaps** (sitemaps.org 0.9) at `/sitemap.xml`, one per kind with a public page, and `/robots.txt` naming them, after Heratio's `PublicSitemapController`. The old static `public/robots.txt` is gone.
- **An Atom feed** (RFC 4287) at `/feed.atom` of the last 50 archival descriptions made public, each with its scope and content, after Heratio's `FeedController`.
- **METS 1.12** as an export format of a unit and everything inside it: each unit's Dublin Core in its own `dmdSec`, the files the reader may receive in the `fileSec` by use with their SHA-256, and a logical `structMap`, after Heratio's `MetsController`.
- **GraphQL**, read only, at `/api/ric/v1/graphql` (GET or POST), after Heratio's `ahg-graphql`: records, agents, repositories, places, activities and rules, one by id or a page of up to 100, with aliases and variables. Heratio matched the query by pattern and answered a fixed shape; the Workspace parses it, answers the fields asked for and refuses one it does not have, as GraphQL says. Mutations are refused (ADR 0007). It is a subset: no fragments, directives or nested selections.
- **Places within a box or radius** at `/api/ric/v1/places/within`, as GeoJSON (RFC 7946), after Heratio's `ahg-gis`. Heratio searched descriptions' own coordinates; in the Workspace coordinates belong to places, which descriptions point to. A box across the antimeridian works; distance is the haversine distance.

**2A: caption tracks, WebVTT only, no HLS.** After Heratio's `ahg-media-streaming` caption tracks.

- **Kept.** Subtitles, captions, audio descriptions and chapters, one of each kind for each language, one shown by default, on a sound or video instantiation. Each is checked as WebVTT (the header, every cue's timing, none ending before it starts) and kept in the file store under its checksum. Heratio kept the text in its database row.
- **Who receives one.** Who may receive the recording it goes with: a track says what the recording says. Withdraw the recording and its captions go with it.
- **Shown.** As `<track>` elements in the player, and in the IIIF manifest as supplementing annotations on the canvas (Presentation 3 cookbook 0219).
- **The player now plays the viewing copy** where there is one, as the IIIF manifest paints it, so the public, who never receive a master, hear and see the recording on the record's page. Before 22c the page showed only the thumbnail to the public.

### Not carried in 22c

- Writes through the API, GraphQL mutations, and Heratio's API write endpoints (ADR 0007).
- HLS and transcoding on request (2A); the Workspace's viewing copies are made when the file is kept.
- Heratio's caption fetch from a remote URL (a server fetching addresses users give is the SSRF webhooks are guarded against), SRT conversion, and its active switch (remove a track instead). Its SDH flag is WebVTT's captions kind.
- Heratio's GIS map pages; places already show their map.

### Evidence (22c)

- `tests/Feature/WebhooksTest.php`: only public addresses; a delivery signed and holding no content; one per entity and event; a publish told as published; retries on Heratio's schedule; the secret shown once.
- `tests/Feature/InterfacesTest.php`: sitemaps, robots.txt and the feed without what the public may not read; METS with units, files and checksums; OpenAPI from the routes; GraphQL answering only what is published, with aliases and variables, refusing mutations, unknown fields and limits over 100; places within a box and a radius as GeoJSON; caption tracks checked, played publicly with the recording, offered in the manifest, and withheld with it.
- `tests/Feature/AccessMatrixTest.php`: webhooks and caption tracks refused to another institution's administrator.

## Decision (22d: vendors), 3 November 2026

After Heratio's `ahg-vendor`: the conservators, binders, digitisation bureaux, framers and carriers an institution sends records to, and the work sent.

**A vendor is an authority record.** This is the same choice Dr Johan Pieterse made for donors in phase 9: no second store of agents. The vendor profile holds what Heratio's `ahg_vendors` holds beyond a name and contact details:

- the vendor code (VND, the year and a number, as Heratio gives them);
- the type and status (vocabulary rows);
- registration and VAT numbers;
- bank details, encrypted as Heratio encrypts them and kept out of the audit log;
- insurance;
- three ratings;
- the preferred and B-BBEE flags.

The names, contact points and relations stay on the authority record, which links to its profile. A vendor that has done work is made inactive rather than deleted.

**Services.** Heratio's sixteen service types are a vocabulary. What each needs at the institution is a row per institution, with Heratio's values as defaults: whether it needs insurance, whether it needs a valuation, its usual number of days, and whether it is offered. A vendor offers services at hourly or fixed rates in a currency.

**Transactions.** Work is requested of a vendor for one service and numbered TXN, the year and month, and a number. It carries Heratio's dates, costs, payment status, insurance, shipping and notes. The date it is due back defaults to the service's usual days. Each status change stamps what Heratio stamps (approved, sent, returned, with who did it) and is kept in the history. The records sent are items with their condition before and after (graded and described), a declared value, the work and its cost. They are dated out and back with the work. Quotes, invoices, condition reports and photographs are kept in the file store.

**Changed from Heratio**, which let any status follow any other and sent anything:

- Records leave only once the work is approved and has records.
- A service that needs insurance goes only to a vendor whose cover is in force, or with insurance arranged for the work.
- A service that needs a valuation goes only once every record has a declared value.
- A record already out with a vendor is not sent with other work until it is back.
- Work goes only to an active vendor.
- Returned or cancelled work is over.

**RiC.** A transaction is shown as a `rico:Activity` that the vendor performs (`rico:isOrWasPerformedBy`), of a type (`rico:hasActivityType`), affecting the records sent (`rico:affectsOrAffected`). In 22d it is kept for staff and not exported.

### Not carried in 22d

- Heratio's contract tables (`ahg_contract` and the tables around it): its vendor screens never used them, and donor agreements already cover the institution's own agreements.
- Its stored monthly metrics table: the dashboard and a vendor's figures are worked out when shown.
- Heratio's encryption of a vendor's telephone and email: these are the authority record's contact points, kept as every contact point is.

### Evidence (22d)

- `tests/Feature/VendorsTest.php`:
  - an authority record made a vendor, with codes in turn and bank details encrypted and absent from the audit log;
  - a second profile for the same record, and a bad rating, refused;
  - a vendor with work not deleted;
  - work refused for a service the vendor does not offer, and the due date taken from the service;
  - sending refused before approval, without records, without insurance and without a valuation;
  - a record out with one vendor refused to another;
  - the history kept;
  - closed work not reopened, and an inactive vendor given no work;
  - the pages, documents and service settings, and a contributor refused.
- `tests/Feature/AccessMatrixTest.php`: vendors, transactions, their records and documents refused to another institution's administrator.
