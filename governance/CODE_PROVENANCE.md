# Code provenance ledger

Every file in this repository that was not written for it is listed here before it is committed: where it came from, at which commit, who wrote it, under which licence, and who cleared it. Nothing from Heratio or any other source is copied before gate G1 (the legal and architecture baseline) has passed and the file has an approved row below.

## Rules

- A file is either written for the Workspace, or copied with a row in this ledger. There is no third kind.
- A copied file keeps its original copyright and licence header and gains a line naming its source commit.
- Licences must be compatible with AGPL-3.0-or-later. Anything uncertain is rewritten, not copied.
- The prohibited-copy list (below) is checked before any copy; it names code that may not be reused, for example because its ownership is unclear or it depends on Heratio at run time.

## Pinned sources

| Source | Repository | Commit | Audited on | Audited by |
|---|---|---|---|---|
| Heratio (baseline audit only; nothing cleared to copy) | ArchiveHeritageGroup/heratio | `74239a174a941ca2a6a53ed26395d7be7b4ab134` | 2026-10-19 | draft, for review by Dr Johan Pieterse (see `G1-HERATIO-SOURCE-AUDIT.md`) |
| OpenRiC reference service v0.17.25 (the RiC-O term mapping only; nothing cleared to copy) | openric/service | `c58ed28e6165d011ad285a50d8af772c520d8168` | 2026-10-19 | draft, for review by Dr Johan Pieterse; licence headers on each file to be checked at G1 |

## Copied files

| File here | Source file | Source commit | Original authors | Licence | Change made | Cleared by | Date |
|---|---|---|---|---|---|---|---|
| (none yet) | | | | | | | |

## Prohibited-copy list

Draft from the G1 source audit of 19 October 2026, to be confirmed at G1:

1. Any file whose copyright or authorship is not Johan Pieterse, Plain Sailing Information Systems or The AHG, or cannot be established.
2. The 12 Heratio views noted as ported from `ahgThemeB5Plugin` templates (`ahg-user-manage`, `ahg-menu-manage`), until each is compared with AtoM's own templates.
3. Any code that reads or writes the AtoM/Qubit schema, as working code. It may be read for behaviour; its data access is rewritten.
4. Any code that calls Heratio, its database, its triplestore or its services at run time.
5. Packages proposed as out of scope for V0.1 (GLAM sector and AI), unless a decision record brings one in.
6. Anything copied without a row in the table above.
7. The RiC-O class and property choices in Heratio's `ahg-ric` (at the pinned commit and at `a060339c5`, 19 October 2026). They type nodes with names RiC-O 1.1 does not define, among them `rico:Production`, `rico:Accumulation`, `rico:Occupation`, `rico:Function`, `rico:DateRange` and `rico:RecordList`, which OpenRiC corrected from the RiC-O 1.1 namespace remediation of spec v0.37.0 to reference service v0.17.25. Term choices follow the reference service at the pinned commit above and the published extension ontology, and every build is checked as `conformance/README.md` sets out.
