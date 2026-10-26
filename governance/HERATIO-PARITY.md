# Heratio parity register

Where each Heratio package stands in the OpenRiC Workspace. The end product has Heratio's functionality on the Workspace's RiC-native model (ROADMAP.md, Next phases); a package is at parity when its functions work here, whether its code was ported, written fresh or replaced by something the Workspace already does. Status is an assessment of 26 October 2026, against heratio-dev's 120 general packages (`ahg-mva-claims`, one client's claims intake, is not part of parity); it is updated with each phase.

- **Covered**: the Workspace does what the package does, to be confirmed against Heratio function by function as each area is finished.
- **Partial**: some of it exists; the phase completes it.
- **Planned**: nothing yet; the phase brings it.

Scope and size are from the G1 audit (`g1-heratio-packages-74239a1.csv`).

| Package | Lines | G1 scope | Status | Phase | Workspace today |
|---|---|---|---|---|---|
| `ahg-3d-model` | 7687 | out: GLAM sector | Planned | 21 |  |
| `ahg-access-request` | 1928 | V0.1 candidate | Planned | 7 |  |
| `ahg-accession-manage` | 6795 | later: V1.0+ | Planned | 9 |  |
| `ahg-acl` | 9750 | V0.1 candidate | Covered |  | Roles and permissions with default deny (ADR 0005), written fresh |
| `ahg-actor-manage` | 13061 | V0.1 candidate | Covered |  | Authority records: ISAAR(CPF), merge, split, duplicates, completeness |
| `ahg-ai-chatbot` | 4924 | out: AI (V2+) | Planned | 22 |  |
| `ahg-ai-compliance` | 4043 | out: AI (V2+) | Planned | 22 |  |
| `ahg-ai-services` | 30242 | out: AI (V2+) | Partial | 17 | Proposals and their review queue (v0.0.47, v0.0.48); extraction in phases 5 and 17 |
| `ahg-annotations` | 1286 | V0.1 candidate | Planned | 14 | As IIIF annotations |
| `ahg-api` | 30902 | V0.1 candidate | Partial | 22 | Read API, keys, proposals, files (ADR 0007); GraphQL in phase 22 |
| `ahg-api-plugin` | 179 | V0.1 candidate | Covered |  | The proposals contract the MCP uses (v0.0.47) |
| `ahg-archaeology` | 4291 | out: GLAM sector | Planned | 21 |  |
| `ahg-archivematica` | 4049 | later: V1.0+ | Planned | 15 |  |
| `ahg-articles` | 2447 | later: V1.0+ | Planned | 20 |  |
| `ahg-artwork-request` | 1844 | out: GLAM sector | Planned | 21 |  |
| `ahg-audit-trail` | 4072 | V0.1 candidate | Covered |  | Tamper-evident audit log (ADR 0004) |
| `ahg-authority-resolution` | 12466 | V0.1 candidate | Partial | 17 | A proposal may name the existing record; candidate ranking in phase 17 |
| `ahg-backup` | 5683 | later: V1.0+ | Partial | 3 | Database backup before each deploy |
| `ahg-biblio-bf` | 3060 | out: GLAM sector | Planned | 21 |  |
| `ahg-biblio-frbr` | 2006 | out: GLAM sector | Planned | 21 |  |
| `ahg-c2pa` | 17272 | V0.1 candidate | Planned | 22 |  |
| `ahg-cart` | 2166 | later: V1.0+ | Planned | 19 |  |
| `ahg-cdpa` | 2774 | later: jurisdiction | Planned | 18 |  |
| `ahg-condition` | 1690 | later: V1.0+ | Planned | 21 |  |
| `ahg-core` | 60173 | V0.1 candidate | Covered |  | Framework, not a function: the Workspace has its own (Laravel, PostgreSQL) |
| `ahg-custom-fields` | 1338 | V0.1 candidate | Planned | 22 | As description elements in local vocabularies |
| `ahg-dacs-manage` | 1084 | V0.1 candidate | Planned | 11 | Description templates beside ISAD(G) |
| `ahg-dam` | 4736 | out: GLAM sector | Planned | 21 |  |
| `ahg-data-migration` | 5270 | later: V1.0+ | Partial | 4 | AtoM CSV and EAD import with dry runs and reports |
| `ahg-dc-manage` | 639 | V0.1 candidate | Partial | 11 | Dublin Core export; a DC template in phase 11 |
| `ahg-dedupe` | 3144 | later: V1.0+ | Partial | 22 | Duplicate authority records; other kinds later |
| `ahg-discovery` | 5836 | V0.1 candidate | Covered |  | Public catalogue, relationship graph, OAI-PMH |
| `ahg-display` | 10111 | V0.1 candidate | Partial | 20 | Public catalogue display; display profiles later |
| `ahg-doi` | 0 | later: V1.0+ | Planned | 20 |  |
| `ahg-doi-manage` | 4884 | later: V1.0+ | Planned | 20 |  |
| `ahg-donor-manage` | 3241 | later: V1.0+ | Planned | 9 |  |
| `ahg-dropdown-manage` | 1744 | V0.1 candidate | Covered |  | Every list is a SKOS vocabulary, editable |
| `ahg-exhibition` | 26806 | out: GLAM sector | Planned | 21 |  |
| `ahg-export` | 1256 | V0.1 candidate | Covered |  | EAD, Dublin Core, MODS, CSV and RiC-O export |
| `ahg-extended-rights` | 7869 | V0.1 candidate | Planned | 2, 13 | Agent embargoes exist |
| `ahg-favorites` | 2634 | later: V1.0+ | Planned | 19 |  |
| `ahg-federation` | 15815 | later: V1.0+ | Planned | 16 |  |
| `ahg-feedback` | 879 | later: V1.0+ | Planned | 19 |  |
| `ahg-forms` | 2541 | V0.1 candidate | Planned | 22 |  |
| `ahg-ftp-upload` | 2301 | later: V1.0+ | Planned | 22 |  |
| `ahg-function-manage` | 2606 | V0.1 candidate | Covered |  | Activities with ISDF elements |
| `ahg-functions-docs` | 716 | later: V1.0+ | Planned | 20 |  |
| `ahg-gallery` | 7210 | out: GLAM sector | Planned | 21 |  |
| `ahg-gis` | 280 | V0.1 candidate | Partial | 22 | Places have coordinates and containment; maps later |
| `ahg-graphql` | 764 | later: V1.0+ | Planned | 22 |  |
| `ahg-harris-matrix` | 1343 | out: GLAM sector | Planned | 21 |  |
| `ahg-help` | 5771 | later: V1.0+ | Planned | 20 |  |
| `ahg-heritage-manage` | 12679 | V0.1 candidate | Planned | 21 | Heritage asset register, with the museum domain |
| `ahg-icip` | 6793 | later: jurisdiction | Planned | 18 |  |
| `ahg-iiif-collection` | 8745 | out: GLAM sector | Planned | 14 |  |
| `ahg-image-ar` | 896 | out: GLAM sector | Planned | 21 |  |
| `ahg-inference-receipts` | 1569 | out: AI (V2+) | Partial | 17 | Its signing code is copied for the audit chain |
| `ahg-information-object-manage` | 47506 | V0.1 candidate | Covered |  | Records and record sets: ISAD(G), hierarchy, levels, instantiations, publication |
| `ahg-ingest` | 5338 | later: V1.0+ | Planned | 22 |  |
| `ahg-integrity` | 3078 | later: V1.0+ | Partial | 12 | Fixity checked on demand |
| `ahg-ipsas` | 2129 | later: jurisdiction | Planned | 18 |  |
| `ahg-jobs` | 950 | later: V1.0+ | Planned | 5 |  |
| `ahg-jobs-manage` | 691 | later: V1.0+ | Planned | 12 |  |
| `ahg-label` | 1383 | later: V1.0+ | Planned | 22 |  |
| `ahg-landing-page` | 3557 | later: V1.0+ | Planned | 20 |  |
| `ahg-library` | 45610 | out: GLAM sector | Planned | 21 |  |
| `ahg-loan` | 3798 | out: GLAM sector | Planned | 21 |  |
| `ahg-marketing` | 726 | later: V1.0+ | Planned | 20 |  |
| `ahg-marketplace` | 19926 | out: GLAM sector | Planned | 22 |  |
| `ahg-media-processing` | 2733 | V0.1 candidate | Partial | 5 | Reference copies and thumbnails made on upload |
| `ahg-media-streaming` | 1727 | V0.1 candidate | Planned | 22 |  |
| `ahg-menu-manage` | 1790 | V0.1 candidate | Covered |  | Navigation written fresh |
| `ahg-metadata-export` | 15322 | V0.1 candidate | Partial | 11 | EAD, DC, MODS, CSV, RiC-O; more crosswalks in phase 11 |
| `ahg-metadata-extraction` | 2770 | V0.1 candidate | Planned | 5 |  |
| `ahg-mods-manage` | 956 | out: GLAM sector | Partial | 21 | MODS export |
| `ahg-multi-tenant` | 2626 | later: V1.0+ | Covered |  | An institution per host, isolated by design |
| `ahg-museum` | 7661 | out: GLAM sector | Planned | 21 |  |
| `ahg-narssa` | 581 | later: jurisdiction | Planned | 18 |  |
| `ahg-naz` | 3600 | later: jurisdiction | Planned | 18 |  |
| `ahg-nmmz` | 2666 | later: jurisdiction | Planned | 18 |  |
| `ahg-oai` | 1847 | V0.1 candidate | Covered |  | OAI-PMH, Export-Only profile |
| `ahg-observability` | 1903 | later: V1.0+ | Planned | 12 |  |
| `ahg-ocfl` | 3106 | later: V1.0+ | Planned | 15 |  |
| `ahg-pdf-tools` | 2093 | V0.1 candidate | Planned | 5 |  |
| `ahg-portable-export` | 3000 | later: V1.0+ | Planned | 11 |  |
| `ahg-preservation` | 10211 | later: V1.0+ | Planned | 15 |  |
| `ahg-privacy` | 16854 | later: jurisdiction | Planned | 18 |  |
| `ahg-provenance-ai` | 2662 | out: AI (V2+) | Planned | 17 |  |
| `ahg-rad-manage` | 1233 | V0.1 candidate | Planned | 11 |  |
| `ahg-rdm` | 2739 | later: V1.0+ | Planned | 22 |  |
| `ahg-records-manage` | 10687 | later: V1.0+ | Planned | 13 |  |
| `ahg-reports` | 13861 | later: V1.0+ | Partial | 8 | Authority completeness dashboard |
| `ahg-repository-manage` | 4578 | V0.1 candidate | Covered |  | Repositories with ISDIAH elements and contact points |
| `ahg-request-publish` | 1876 | V0.1 candidate | Partial | 10 | Review and publish of each value; requests in phase 10 |
| `ahg-research` | 79899 | V0.1 candidate | Planned | 19 | Decision first |
| `ahg-researcher-manage` | 3111 | V0.1 candidate | Partial | 7 | Registration requests |
| `ahg-resourcesync` | 926 | later: V1.0+ | Planned | 16 |  |
| `ahg-ric` | 28399 | V0.1 candidate | Partial | 11, 17 | RiC-native model, RiC-O export, assertions with provenance (ADR 0006); not yet the Governance deprecation register, Portability (RiC-O import, round-trip, DCAT/VoID), Inferred-Provenance export or Graph-Grounding (`RicDeprecationService`, `RdfImportService`, `RicDatasetService`, `RicProvenanceService`, `GraphGroundingService`) |
| `ahg-ric-manage` | 1464 | V0.1 candidate | Covered |  | Relations, their types, roles and assertions |
| `ahg-rights` | 0 | V0.1 candidate | Planned | 2 |  |
| `ahg-rights-holder-manage` | 5215 | V0.1 candidate | Planned | 2 |  |
| `ahg-scan` | 7065 | later: V1.0+ | Planned | 22 |  |
| `ahg-search` | 7659 | V0.1 candidate | Partial | 6 | Search by name and identifier |
| `ahg-security-clearance` | 9206 | later: jurisdiction | Planned | 18 |  |
| `ahg-semantic-search` | 29749 | out: AI (V2+) | Planned | 17 |  |
| `ahg-settings` | 16789 | V0.1 candidate | Covered |  | Institution settings, written fresh |
| `ahg-share-link` | 2659 | later: V1.0+ | Planned | 19 |  |
| `ahg-sharepoint` | 6045 | later: V1.0+ | Planned | 22 |  |
| `ahg-spectrum` | 12393 | out: GLAM sector | Planned | 21 |  |
| `ahg-static-page` | 695 | later: V1.0+ | Planned | 20 |  |
| `ahg-statistics` | 1906 | later: V1.0+ | Planned | 8 |  |
| `ahg-storage-manage` | 7645 | V0.1 candidate | Covered |  | Containers and storage places, with movements |
| `ahg-term-taxonomy` | 6138 | V0.1 candidate | Covered |  | SKOS vocabularies: bundled, local, imported, matched |
| `ahg-theme-b5` | 4574 | V0.1 candidate | Covered |  | Interface written fresh |
| `ahg-translation` | 5726 | out: AI (V2+) | Planned | 17 |  |
| `ahg-user-manage` | 5651 | V0.1 candidate | Covered |  | Accounts, registration, roles; written fresh |
| `ahg-vendor` | 6980 | later: V1.0+ | Planned | 22 |  |
| `ahg-version-control` | 3142 | later: V1.0+ | Partial | 22 | Every change is in the audit log; version views later |
| `ahg-workflow` | 7830 | later: V1.0+ | Planned | 10 |  |
| `ahg-z3950` | 5243 | out: GLAM sector | Planned | 21 |  |

: Heratio packages and their Workspace status

Totals: 20 covered, 20 partial, 80 planned, of 120.
