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
