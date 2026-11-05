# Heratio parity register

Where each Heratio package stands in the OpenRiC Workspace. The end product has Heratio's functionality on the Workspace's RiC-native model (ROADMAP.md, Next phases); a package is at parity when its functions work here, whether its code was ported, written fresh or replaced by something the Workspace already does. Status is an assessment of 26 October 2026, against heratio-dev's 120 general packages (`ahg-mva-claims`, one client's claims intake, is not part of parity); it is updated with each phase.

- **Covered**: the Workspace does what the package does, to be confirmed against Heratio function by function as each area is finished.
- **Partial**: some of it exists; the phase completes it.
- **Planned**: nothing yet; the phase brings it.
- **Not carried**: decided against, for the reason given.

Scope and size are from the G1 audit (`g1-heratio-packages-74239a1.csv`).

| Package | Lines | G1 scope | Status | Phase | PSIS plugins | Workspace today |
|---|---|---|---|---|---|---|
| `ahg-3d-model` | 7687 | out: GLAM sector | Planned | 21 | `ahg3DModelPlugin` |  |
| `ahg-access-request` | 1928 | V0.1 candidate | Partial | 7 | `ahgAccessRequestPlugin` | Requests for files on request, approved for some days or refused, each step emailed (v0.0.65); security clearance requests in phase 18 |
| `ahg-accession-manage` | 6795 | later: V1.0+ | Partial | 9 | `ahgAccessionManagePlugin`, `qtAccessionPlugin` | Accessions, CAAIS, deaccessions, CSV and CAAIS export from v0.0.77 (9a); intake from v0.0.79 (9c); appraisal, valuation, containers, rights and the whole add form from v0.0.80 (9d) |
| `ahg-acl` | 9750 | V0.1 candidate | Covered |  | `qbAclPlugin` | Roles and permissions with default deny (ADR 0005), written fresh |
| `ahg-actor-manage` | 13061 | V0.1 candidate | Covered |  | `ahgActorManagePlugin`, `ahgAuthorityPlugin`, `ahgContactPlugin` | Authority records: ISAAR(CPF), merge, split, duplicates, completeness |
| `ahg-ai-chatbot` | 4924 | out: AI (V2+) | Planned | 17 (last) | `ahgChatbotPlugin` |  |
| `ahg-ai-compliance` | 4043 | out: AI (V2+) | Planned | 17 (last) | `ahgAiCompliancePlugin` |  |
| `ahg-ai-services` | 30242 | out: AI (V2+) | Partial | 17a to 17b | `ahgAIPlugin`, `ahgAiConditionPlugin` | Proposals and their review queue (v0.0.47, v0.0.48); the gateway client and OCR of printed pages by a vision model checked against Tesseract (heratio#1522), from v0.0.127; names found by spaCy, summaries and a vision model's condition assessment, each proposed for review, from v0.0.128 (ADR 0025); handwriting once a gateway key lets its answer be checked |
| `ahg-annotations` | 1286 | V0.1 candidate | Planned | 14 | `ahgAnnotationsPlugin` | As IIIF annotations |
| `ahg-api` | 30902 | V0.1 candidate | Covered | 22c | `ahgAPIPlugin`, `arRestApiPlugin` | Read API, keys, proposals, files (ADR 0007); webhooks, OpenAPI, sitemaps, Atom feed and METS from v0.0.108 (ADR 0020); API writes stay proposals |
| `ahg-api-plugin` | 179 | V0.1 candidate | Covered |  | none | The proposals contract the MCP uses (v0.0.47) |
| `ahg-archaeology` | 4291 | out: GLAM sector | Planned | 21 | `ahgArchaeologyPlugin` |  |
| `ahg-archivematica` | 4049 | later: V1.0+ | Done | 15d | `arStorageServicePlugin` | Against Archivematica's API from v0.0.117: transfers followed to their AIP, DIPs fetched from the Storage Service and taken in, its METS parser copied; settings by operators (ADR 0022) |
| `ahg-articles` | 2447 | later: V1.0+ | Done | 20a | none | Articles with their files, sections, related articles, comments and moderation, and reads, from v0.0.123 (ADR 0024) |
| `ahg-artwork-request` | 1844 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-audit-trail` | 4072 | V0.1 candidate | Covered |  | `ahgAuditTrailPlugin` | Tamper-evident audit log (ADR 0004) |
| `ahg-authority-resolution` | 12466 | V0.1 candidate | Done | 17b2 | `ahgAuthorityResolutionPlugin` | All of it, on RiC: mentions from every proposed agent and place with their context, candidates ranked by name and nine kinds of evidence, decisions kept as seen, the park queue with its nightly scan, NER feedback, outside authorities (VIAF, Wikidata, GeoNames; TGN, GND, ISNI and SAGNC listed as Heratio's stubs) filling new records with each field's source, and assignment, from v0.0.129 (ADR 0025) |
| `ahg-backup` | 5683 | later: V1.0+ | Partial | 3 (last) | `ahgBackupPlugin` | Database backup before each deploy; deferred to the end |
| `ahg-biblio-bf` | 3060 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-biblio-frbr` | 2006 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-c2pa` | 17272 | V0.1 candidate | Planned | 17 (last) | `ahgC2paPlugin` | Moved to phase 17 with the AI services its manifests describe, on Dr Johan Pieterse's choice 1B of 3 November 2026 (ADR 0020) |
| `ahg-cart` | 2166 | later: V1.0+ | Covered | 19a | `ahgCartPlugin` | The cart, the shop with PayFast, orders and paid downloads; with the shop closed, requests to publish (ADR 0017). Not Stripe, which Heratio never charges with |
| `ahg-cdpa` | 2774 | later: jurisdiction | Covered | 18e | `ahgCDPAPlugin` | The Cyber and Data Protection Act as a jurisdiction of the privacy registers, with data controller licences and the Data Protection Officer's certificate and form DP2, from v0.0.101 (ADR 0016); a pack switched on in the settings |
| `ahg-condition` | 1690 | later: V1.0+ | Planned | 21 | `ahgConditionPlugin` |  |
| `ahg-core` | 60173 | V0.1 candidate | Covered |  | `ahgAccessibilityPlugin`, `ahgCorePlugin` | Framework, not a function: the Workspace has its own (Laravel, PostgreSQL); its alternative text for images, with the worklist, is the instantiation's alternative-text element (v0.0.64) |
| `ahg-custom-fields` | 1338 | later: V1.0+ | Covered | 22b | `ahgCustomFieldsPlugin` | The institution's own fields on five kinds of entity, from v0.0.107 (ADR 0020) |
| `ahg-dacs-manage` | 1084 | V0.1 candidate | Covered |  | `ahgDacsManagePlugin`, `arDacsPlugin` | DACS display standard, from v0.0.76 |
| `ahg-dam` | 4736 | out: GLAM sector | Planned | 21 | `ahgDAMPlugin` |  |
| `ahg-data-migration` | 5270 | later: V1.0+ | Partial | 4 | `ahgDataMigrationPlugin` | AtoM CSV and EAD import with dry runs and reports |
| `ahg-dc-manage` | 639 | V0.1 candidate | Covered |  | `ahgDcManagePlugin`, `sfDcPlugin` | Dublin Core export, and the Dublin Core display standard from v0.0.76 |
| `ahg-dedupe` | 3144 | later: V1.0+ | Covered | 22b | `ahgDedupePlugin` | Rules, scan, review and merge of duplicate descriptions, from v0.0.107 (ADR 0020); authority records and places already had their own duplicates and merge |
| `ahg-discovery` | 5836 | V0.1 candidate | Covered |  | `ahgDiscoveryPlugin` | Public catalogue, relationship graph, OAI-PMH |
| `ahg-display` | 10111 | V0.1 candidate | Done | 20d | `ahgDisplayPlugin` | The archival part: display profiles by level of description (elements, order, labels, tree, pictures, actions) on the public page; browse settings for the institution and each account (view, sort and direction, page size, images); sorting by earliest and latest date; print and CSV of the browse list, from v0.0.126 (ADR 0024). The museum, library and gallery collection types are phase 21 |
| `ahg-doi` | 0 | later: V1.0+ | Done | 20c | none | Its tables (`ahg_doi`, `ahg_doi_config`, `ahg_doi_queue`, `ahg_doi_log`) are carried with `ahg-doi-manage`, from v0.0.125 |
| `ahg-doi-manage` | 4884 | later: V1.0+ | Done | 20c | `ahgDoiPlugin` | DOIs for units of description at DataCite: minting, drafts, updates, verification, hiding, the queue, minting on publish, batches, funding, usage events and the report, from v0.0.125 (ADR 0024) |
| `ahg-donor-manage` | 3241 | later: V1.0+ | Partial | 9 | `ahgDonorAgreementPlugin`, `ahgDonorManagePlugin` | Donors as authority records with contact points, and the donor list, from v0.0.77 (9a); donor agreements with their rights, restrictions, reminders and documents from v0.0.78 (9b) |
| `ahg-dropdown-manage` | 1744 | V0.1 candidate | Done | 20d | none | Every list is a bundled SKOS vocabulary; an institution words, orders and defaults any of them for itself, and adds and hides terms in the open ones, from v0.0.126 (ADR 0024). Before 20d this row said the lists were editable, which they were not |
| `ahg-exhibition` | 26806 | out: GLAM sector | Planned | 21 | `ahgExhibitionPlugin` |  |
| `ahg-export` | 1256 | V0.1 candidate | Covered |  | `ahgExportPlugin` | EAD, Dublin Core, MODS, CSV and RiC-O export |
| `ahg-extended-rights` | 7869 | V0.1 candidate | Partial | 13 | `ahgExtendedRightsPlugin` | Rights statements, licences, grants, batch rights and the rights report (v0.0.57 to v0.0.59); embargoes of records and their files, with exceptions, notices and the list from v0.0.89 (13a, ADR 0015); retention and disposal in 13b |
| `ahg-favorites` | 2634 | later: V1.0+ | Covered | 19a | `ahgFavoritesPlugin` | Favourites of any entity, folders shared by a link, nine export formats, import by IRI (ADR 0017); sending to collections (with notes), projects (as their records) and bibliographies |
| `ahg-federation` | 15815 | later: V1.0+ | Done | 16a, 16b, 16c, 16d | `ahgFederationPlugin` | Peers and signed federated search from v0.0.118; OAI-PMH harvest, the union catalogue and the DSpace and Alma connectors from v0.0.119; governance, discovery, vocabulary sync, the network directory and join requests from v0.0.120; the Europeana EDM export and loans from v0.0.121 (ADR 0023) |
| `ahg-feedback` | 879 | later: V1.0+ | Covered | 19a | `ahgFeedbackPlugin` | Feedback from anyone on the catalogue or a record, handled by staff (ADR 0017) |
| `ahg-forms` | 2541 | later: V1.0+ | Covered | 22b | `ahgFormsPlugin` | Form templates of elements and own fields, assigned by repository, level or collection, from v0.0.107 (ADR 0020) |
| `ahg-ftp-upload` | 2301 | later: V1.0+ | Covered | 22a | `ahgFtpPlugin` | An FTP or SFTP account kept to a watched folder, from v0.0.106 (ADR 0020): files go to the Workspace's own store, not to an FTP server for later fetching |
| `ahg-function-manage` | 2606 | V0.1 candidate | Covered |  | `ahgFunctionManagePlugin` | Activities with ISDF elements |
| `ahg-functions-docs` | 716 | later: V1.0+ | Done | 20d | `ahgFunctionsDocsPlugin` | The code reference, read from the code: PHP classes and methods, Blade templates, routes, console commands and bundled vocabularies, from v0.0.126 (ADR 0024) |
| `ahg-gallery` | 7210 | out: GLAM sector | Planned | 21 | `ahgGalleryPlugin` |  |
| `ahg-gis` | 280 | V0.1 candidate | Covered | 22c | `ahgGISPlugin` | Places have coordinates and containment; places within a box or radius as GeoJSON from v0.0.108 (ADR 0020) |
| `ahg-graphql` | 764 | later: V1.0+ | Covered | 22c | `ahgGraphQLPlugin` | Read-only GraphQL over what the public may read, from v0.0.108 (ADR 0020) |
| `ahg-harris-matrix` | 1343 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-help` | 5771 | later: V1.0+ | Done | 20b | `ahgHelpPlugin` | The Help Center: guides from Markdown with search, categories, sections, links, help in context, the system map and breakdown, from v0.0.124; 73 guides now, each phase adding its own (ADR 0024) |
| `ahg-heritage-manage` | 12679 | V0.1 candidate | Planned | 21 | `ahgHeritagePlugin`, `ahgHeritageAccountingPlugin` | Heritage asset register and heritage accounting, with the museum domain |
| `ahg-icip` | 6793 | later: jurisdiction | Partial | 18 | `ahgICIPPlugin` | Traditional Knowledge and Biocultural labels (v0.0.59); the rest of ICIP in phase 18 |
| `ahg-iiif-collection` | 8745 | out: GLAM sector | Partial | 14 | `ahgIiifPlugin` | Presentation 3 manifests (PSIS's manifest, Heratio's audio and video canvases), fonds collections, curated collections, Change Discovery, Content State, the Mirador viewer and the IIIF-ready gate from v0.0.87 (14a, ADR 0014); Content Search 2.0, Auth Flow 2.0, saved viewer layouts and validation from v0.0.88 (14b); clearance levels on the probe from v0.0.101 (18c); sound and video lengths read by ffprobe and their viewing copies (Heratio's MediaDerivativeService) from v0.0.102; NER annotations in phase 17, 3D reports in phase 21 |
| `ahg-image-ar` | 896 | out: GLAM sector | Planned | 21 | `ahgImageArPlugin` |  |
| `ahg-inference-receipts` | 1569 | out: AI (V2+) | Done | 17a | none | Its signing code is copied for the audit chain; every call a model answers is a receipt in a chained, signed, append-only log, from v0.0.127 (ADR 0025) |
| `ahg-information-object-manage` | 47506 | V0.1 candidate | Covered | 9 | `ahgInformationObjectManagePlugin`, `ahgProvenancePlugin` | Records and record sets: ISAD(G), hierarchy, levels, instantiations, publication; provenance and custody history from v0.0.81 (9e) |
| `ahg-ingest` | 5338 | later: V1.0+ | Covered | 22a | `ahgIngestPlugin` | The import with PSIS's ingest steps: spreadsheets, column mapping with kept mappings, ZIPs with their files, classification, undo, a BagIt bag, grid entry, from v0.0.106 (ADR 0020); OCR and AI processing in phase 17 |
| `ahg-integrity` | 3078 | later: V1.0+ | Covered | 12a | `ahgIntegrityPlugin` | Fixity schedules, runs, the ledger and failures from v0.0.110 (ADR 0021); retention, holds and disposition are phase 13b's |
| `ahg-ipsas` | 2129 | later: jurisdiction | Planned | 21 | `ahgIPSASPlugin` | With heritage accounting |
| `ahg-jobs` | 950 | later: V1.0+ | Covered | 5 | none | Background jobs and their screen (v0.0.63) |
| `ahg-jobs-manage` | 691 | later: V1.0+ | Covered | 12a | `ahgJobsManagePlugin` | The jobs page since phase 5; failed queue jobs on the operations page from v0.0.110 (ADR 0021) |
| `ahg-label` | 1383 | later: V1.0+ | Covered | 22a | `ahgLabelPlugin` | Templates and sheets for records and containers, Code 128 and QR drawn by the Workspace, from v0.0.106 (ADR 0020) |
| `ahg-landing-page` | 3557 | later: V1.0+ | Done | 20a | `ahgLandingPagePlugin` | Landing pages of 20 kinds of block from what the public may read, the default one the catalogue's home, and users' dashboards, from v0.0.123 (ADR 0024) |
| `ahg-library` | 45610 | out: GLAM sector | Planned | 21 | `ahgLibraryPlugin` |  |
| `ahg-loan` | 3798 | out: GLAM sector | Planned | 21 | `ahgLoanPlugin` |  |
| `ahg-marketing` | 726 | later: V1.0+ | Not carried | 20a | none | AHG's own sales pages for Heratio (a comparison with AtoM and a migration lead form into AHG's Workbench); an institution has no use for them (ADR 0024) |
| `ahg-marketplace` | 19926 | out: GLAM sector | Not carried | 22 | `ahgMarketplacePlugin` | Selling items is not an archive's work; archives only to V1.0 (choice 1B, ADR 0020) |
| `ahg-media-processing` | 2733 | V0.1 candidate | Partial | 5 | none | Reference copies and thumbnails in a job, made again on request, the institution's watermark on reference copies (v0.0.64); a watermark per object and by security classification in phase 18, audio and video previews and transcription later |
| `ahg-media-streaming` | 1727 | V0.1 candidate | Partial | 22c | none | Viewing copies made when kept (14a); WebVTT caption tracks from v0.0.108 (ADR 0020); no HLS or transcoding on request |
| `ahg-menu-manage` | 1790 | V0.1 candidate | Covered |  | `ahgMenuManagePlugin` | Navigation written fresh |
| `ahg-metadata-export` | 15322 | V0.1 candidate | Partial | 11 | `ahgMetadataExportPlugin` | EAD 2002, EAD3, DC, MODS, CSV, RiC-O; EAC-CPF import and EAC-F export and import from v0.0.85 (11a); RiC-O import in 11b |
| `ahg-metadata-extraction` | 2770 | V0.1 candidate | Partial | 5 | `ahgMetadataExtractionPlugin` | Embedded metadata read with exiftool and kept on the master; creator, date, title, description and copyright proposed for review (v0.0.64); keywords wait for a subject proposal kind |
| `ahg-mods-manage` | 956 | out: GLAM sector | Covered |  | `ahgModsManagePlugin`, `sfModsPlugin` | MODS export, and the MODS display standard from v0.0.76 |
| `ahg-multi-tenant` | 2626 | later: V1.0+ | Covered |  | `ahgMultiTenantPlugin` | An institution per host, isolated by design; compare with PSIS ahgMultiTenantPlugin, which has more screens |
| `ahg-museum` | 7661 | out: GLAM sector | Planned | 21 | `ahgMuseumPlugin` |  |
| `ahg-narssa` | 581 | later: jurisdiction | Covered | 18d | `ahgNARSSAPlugin` | Transfers packaged as EAD3, master files, a manifest and METS, from records or approved transfer disposals, from v0.0.101 (ADR 0016); with PSIS's SAHRA export permits; a pack switched on in the settings |
| `ahg-naz` | 3600 | later: jurisdiction | Covered | 18e | `ahgNAZPlugin` | Closure periods and protected records kept as embargoes, research permits, records schedules and transfers, from v0.0.101 (ADR 0016); a pack switched on in the settings |
| `ahg-nmmz` | 2666 | later: jurisdiction | Planned | 21 | `ahgNMMZPlugin` | Moved to phase 21 with the heritage objects it registers, by Dr Johan Pieterse on 2 November 2026 |
| `ahg-oai` | 1847 | V0.1 candidate | Covered |  | `arOaiPlugin` | OAI-PMH, Export-Only profile |
| `ahg-observability` | 1903 | later: V1.0+ | Covered | 12a | `ahgObservabilityPlugin` | Health figures as a page and Prometheus text from v0.0.110 (ADR 0021); no tracing or latency histograms |
| `ahg-ocfl` | 3106 | later: V1.0+ | Done | 15b | `ahgOcflPlugin` | OCFL 1.1 as the one object store from v0.0.115, laid out by extension 0004, each version the whole object, objects purged at destruction (ADR 0022); the mirror to a second root is replication, in 15c |
| `ahg-pdf-tools` | 2093 | V0.1 candidate | Partial | 5 | `ahgTiffPdfMergePlugin` | A PDF's text layer proposed as its transcript (v0.0.64); merging TIFFs into a PDF with the scanning workflows, phase 22 |
| `ahg-portable-export` | 3000 | later: V1.0+ | Planned | 11c, deferred | `ahgPortableExportPlugin` | Read PSIS ahgPortableExportPlugin too, which does more; deferred by Dr Johan Pieterse on 30 October 2026 |
| `ahg-preservation` | 10211 | later: V1.0+ | Done | 15a, 15c, 15d | `ahgPreservationPlugin` | PREMIS events, PRONOM formats by Siegfried and the PREMIS 3.0 export from v0.0.114; normalisation, migration plans, format risk, replication and PREMIS rights from v0.0.116; OAIS packages as BagIt bags from v0.0.117 (ADR 0022); backup verification in phase 3 |
| `ahg-privacy` | 16854 | later: jurisdiction | Partial | 18a, 18b | `ahgPrivacyPlugin` | POPIA governance (registers, requests, compromises, assessments, controls, report) from v0.0.91 (18a, ADR 0016); PII scanning, field redaction and file screening from v0.0.94 (18b); not visual redaction of images, named-entity recognition, the Article 30 export or the autopilot |
| `ahg-provenance-ai` | 2662 | out: AI (V2+) | Done | 17a | none | The log of inferences with each call's trace to its proposals and reviewers, the reviewer's correction kept, and the Inferred-Provenance profile in RiC-O, from v0.0.127 (ADR 0025) |
| `ahg-rad-manage` | 1233 | V0.1 candidate | Covered |  | `ahgRadManagePlugin`, `sfRadPlugin` | RAD display standard, from v0.0.76 |
| `ahg-rdm` | 2739 | later: V1.0+ | Planned | 17 (last) | `ahgRdmPlugin` | Moved to phase 17: it orchestrates ingest, research and the AI services (choice 1B, ADR 0020) |
| `ahg-records-manage` | 10687 | later: V1.0+ | Covered | 13b, 13c | `ahgRecordsManagePlugin` | Retention schedules, disposal classes, reviews, the disposal workflow and destruction certificates from v0.0.90 (13b, ADR 0015); file plans and their imports, e-mail capture, classification rules, declaration, vital records and compliance assessments from v0.0.100 (13c, Dr Johan Pieterse's choices 1A and 2A of 2 November 2026); IMAP polling not yet |
| `ahg-reports` | 13861 | later: V1.0+ | Done | 8 | `ahgReportBuilderPlugin`, `ahgReportsPlugin` | Reports (overview, data quality, and six lists with filters and CSV), the authority completeness dashboard and the rights report; the report builder (saved reports, columns from the standards, sections, templates, export as CSV, XLSX, PDF and Word); the accession and donor reports come with phase 9; schedules with email, an archive of the files made, expiring share links and versions; not its comments, attachments, links, widgets, data snapshots or cover page |
| `ahg-repository-manage` | 4578 | V0.1 candidate | Covered |  | `ahgRepositoryManagePlugin` | Repositories with ISDIAH elements and contact points |
| `ahg-request-publish` | 1876 | V0.1 candidate | Partial | 10 | `ahgRequestToPublishPlugin` | Review and publish of each value; requests in phase 10 |
| `ahg-research` | 79899 | V0.1 candidate | Partial | 19a to 19j | `ahgResearchPlugin` | All of it, decided by Dr Johan Pieterse on 31 October 2026 (ADR 0017); 19a to 19g and 19i in v0.0.95, 19h and 19j in v0.0.99 (offline sync with 11c); parked on 1 November 2026; its AI parts in phase 17 |
| `ahg-researcher-manage` | 3111 | V0.1 candidate | Covered | 19e | `ahgResearcherPlugin` | Registration requests. The package itself is researcher submissions (descriptions and files drafted by researchers for staff review), not a register of researchers, so it moved to phase 19 |
| `ahg-resourcesync` | 926 | later: V1.0+ | Done | 16e | `ahgResourceSyncPlugin` | The source description, capability list, resource list and change list at Heratio's addresses, paged by ?page= at Heratio's 1,000, the change list thirty days back unless asked, withdrawn entities as deletions, from v0.0.122 (ADR 0023) |
| `ahg-ric` | 28399 | V0.1 candidate | Partial | 11, 17, 23, 24 | `ahgRicExplorerPlugin` | RiC-native model, RiC-O export, assertions with provenance (ADR 0006); the Governance deprecation register and Portability (RiC-O import, round trip, DCAT/VoID, change feed) from v0.0.86 (11b, ADR 0013); rules and mandates as entities after Heratio's `ric_rule`, every relation in RiC-O, Authority & Context and Graph Traversal, from v0.0.97 (23b, ADR 0018); several parents as proxies, functions, Digital Object Linkage and Provenance & Event from v0.0.98 (24, ADR 0019); not yet Inferred-Provenance export or Graph-Grounding (`RicProvenanceService`, `GraphGroundingService`, phase 17) |
| `ahg-ric-manage` | 1464 | V0.1 candidate | Covered |  | `ahgRicManagePlugin` | Relations, their types, roles and assertions |
| `ahg-rights` | 0 | V0.1 candidate | Covered |  | `ahgRightsPlugin` | Rights as rico:Rule on records and files (v0.0.57 to v0.0.59, ADR 0008) |
| `ahg-rights-holder-manage` | 5215 | V0.1 candidate | Covered |  | `ahgRightsHolderManagePlugin` | Rights holders are agents, linked by rico:hasOrHadIntellectualPropertyRightsHolder (v0.0.57) |
| `ahg-scan` | 7065 | later: V1.0+ | Partial | 22a | `ahgScanPlugin` | Watched folders, Heratio's sidecars, BagIt intake and the scan API, from v0.0.106 (ADR 0020); not its sector routing, rights enforcement or web archiving |
| `ahg-search` | 7659 | V0.1 candidate | Partial | 17 | `ahgSearchPlugin` | Full-text search with facets, staff search and statistics (v0.0.60, v0.0.61, ADR 0009); search by meaning from v0.0.131 (17d1) |
| `ahg-security-clearance` | 9206 | later: jurisdiction | Covered | 18c, 12b | `ahgSecurityClearancePlugin` | Levels, compartments, clearances, classified records with inheritance and declassification, requests for access, all in the one read check, from v0.0.101 (ADR 0016); TOTP two-factor sign-in and its policy from v0.0.111 (ADR 0021); WebAuthn and codes by email not carried |
| `ahg-semantic-search` | 29749 | out: AI (V2+) | Partial | 17d1 to 17d4 | none | Search by meaning on pgvector with gateway embeddings, widening by SKOS labels and a model, reranking, related records, /ground and the ways into the collection, from v0.0.131 (ADR 0025); repatriation claims, dialogue, shared records, community knowledge and virtual return from v0.0.132 (17d2); displaced and endangered heritage 17d3, language corpus, scholarship and research leads 17d4 |
| `ahg-settings` | 16789 | V0.1 candidate | Covered |  | `ahgSettingsPlugin` | Institution settings, written fresh |
| `ahg-share-link` | 2659 | later: V1.0+ | Covered | 19a | `ahgTimeLimitedShareLinkPlugin` | Share links that never open more than their issuer may read, with their log and prune (ADR 0017); clearance with 18c |
| `ahg-sharepoint` | 6045 | later: V1.0+ | Planned | 22, pilot | `ahgSharePointPlugin` | When a pilot with a Microsoft tenant needs it (choice 2B, ADR 0020) |
| `ahg-spectrum` | 12393 | out: GLAM sector | Planned | 21 | `ahgSpectrumPlugin` |  |
| `ahg-static-page` | 695 | later: V1.0+ | Done | 20a | `ahgStaticPagePlugin` | Static pages in each language, about, privacy and terms at their own addresses, from v0.0.123 (ADR 0024) |
| `ahg-statistics` | 1906 | later: V1.0+ | Done | 8 | `ahgStatisticsPlugin` | Views and downloads of the public catalogue by day, item and repository, web crawlers apart, CSV; no address, user agent or place is kept, so there is no count by country |
| `ahg-storage-manage` | 7645 | V0.1 candidate | Covered |  | `ahgStorageManagePlugin` | Containers and storage places, with movements |
| `ahg-term-taxonomy` | 6138 | V0.1 candidate | Covered |  | `ahgTermTaxonomyPlugin` | SKOS vocabularies: bundled, local, imported, matched |
| `ahg-theme-b5` | 4574 | V0.1 candidate | Covered |  | `ahgThemeB5Plugin`, `ahgUiOverridesPlugin` | Interface written fresh |
| `ahg-translation` | 5726 | out: AI (V2+) | Done | 17c | none | Machine translation of a unit's elements through the gateway's NLLB-200 route, each proposed for review in the target language; the institution's own wording of the interface, proposed, accepted and kept in its history, with machine suggestions, from v0.0.130 (ADR 0025) |
| `ahg-user-manage` | 5651 | V0.1 candidate | Covered | 12b | `ahgUserManagePlugin`, `ahgUserRegistrationPlugin`, `arOidcPlugin` | Accounts, registration, roles; written fresh; OpenID Connect single sign-on after AtoM's `arOidcPlugin`, which Heratio did not carry, and TOTP two-factor sign-in from v0.0.111 (ADR 0021) |
| `ahg-vendor` | 6980 | later: V1.0+ | Covered | 22d | `ahgVendorPlugin` | Vendors on authority records, services, transactions with records, condition, history and documents, from v0.0.109 (ADR 0020); contracts not carried |
| `ahg-version-control` | 3142 | later: V1.0+ | Covered | 22b | `ahgVersionControlPlugin` | Versions of descriptions and authority records, compared and restored, from v0.0.107 (ADR 0020) |
| `ahg-workflow` | 7830 | later: V1.0+ | Covered | 10 | `ahgWorkflowPlugin` | Workflows, steps, tasks, pool, assignment, decisions, team work, history and bulk operations from v0.0.82 (10a); queues, service levels, overdue notices, publish gates, readiness and the diagram from v0.0.84 (10b); Spectrum procedures in phase 21 |
| `ahg-z3950` | 5243 | out: GLAM sector | Planned | 21 | none |  |

: Heratio packages and their Workspace status

Totals: 23 covered, 25 partial, 72 planned, of 120 (27 October 2026).

## PSIS plugins without a Heratio package

Checked on 27 October 2026 against PSIS (`archive/plugins`, 143 plugins: 124 of AHG's and 19 of AtoM's own). Every other PSIS plugin is named beside its Heratio twin in the table above.

| PSIS plugin | What it does | Where it goes |
|---|---|---|
| `ahgEmailDeliveryPlugin` | Bounce capture, suppression list, a gate before each send | Done in v0.0.65 from Heratio's `app/` (`EmailBounceController`, `EmailSuppressionGate`, `EmailAuditListener`), with a screen for sent mail and held addresses |
| `ahgSAHRAPlugin` | SAHRA heritage permits under the National Heritage Resources Act | Section 32 export permits from v0.0.101 (18d); its archaeology, palaeontology, structures and burial permits are not an archive's work |
| `ahgSiteRecordPlugin` | Archaeological, heritage and rock art site records, locality shown by role | Phase 21 |
| `ahgRegistryPlugin` | The AHG community hub and registry | Out of scope: a separate product |
| `ahgUiOverridesPlugin` | Overrides of AtoM module actions | Not needed: specific to AtoM |
| `qtSwordPlugin` | SWORD deposit | Done, v0.0.117: SWORD 1.3 service document and METSArchivematicaDIP deposit, written fresh, signed in with an API key (ADR 0022) |
| `arCasPlugin` | CAS single sign-on | Not planned; OIDC in phase 12 |
| `arOpenSearchPlugin` | OpenSearch description of the catalogue search | Not planned; small, can follow phase 6 if asked |
| `arDominionB5Plugin`, `sfWebBrowserPlugin`, `sfPluginAdminPlugin`, `sfDrupalPlugin` | AtoM's theme, browser and plugin plumbing | Not needed |
| `sfIsadPlugin`, `sfIsaarPlugin`, `sfIsdiahPlugin`, `sfIsdfPlugin`, `sfEadPlugin`, `sfEacPlugin`, `sfSkosPlugin`, `sfHistoryPlugin`, `sfThumbnailPlugin`, `sfTranslatePlugin` | AtoM's standards templates, exports, SKOS, history, thumbnails, translation | Covered by the Workspace's own descriptions, exports, vocabularies, audit log, reference copies and interface languages |

: PSIS plugins without a Heratio package

Where PSIS does more than Heratio (count of screens and actions): IIIF 81 against 51, ingest 21 against 14, portable export 18 against 11, multi-tenancy 25 against 11. Those phases read PSIS's behaviour as well as Heratio's code.

## Field by field

Asked for by Dr Johan Pieterse on 28 October 2026: each Workspace screen has a place for every field of the matching Heratio screen. Heratio's fields are the input names of its edit forms (`packages/ahg-*/resources/views/edit.blade.php` and their partials, at `1aa1bb0b5`). "Same" means the same field under the same label; "modelled" means RiC keeps it as a name, identifier, date or relation row, shown on the same page; "missing" is a gap to close or to agree with Dr Johan Pieterse.

### Archival description (`ahg-information-object-manage`)

| Heratio field | Workspace | Status |
|---|---|---|
| `identifier` | Identifier | Same |
| `altIds[label, value]` (alternative identifiers) | Identifiers, with a type (previous, barcode, other) | Modelled |
| `title` | Title | Same |
| `alternate_title` | Alternate title (names of the other type) | Same, from v0.0.73 |
| `edition` | Edition | Same, from v0.0.73 |
| `level_of_description_id` | Level of description | Same |
| `repository_id` | Repository | Same |
| `events[type, actor, date, start, end]` (dates and their actors) | Dates (creation, accumulation and others, as written and normalised) and the creation relation | Modelled |
| `creators` (name of creator) | Creation and accumulation relations | Modelled |
| `extent_and_medium` | Extent and medium | Same |
| `archival_history`, `acquisition`, `scope_and_content`, `appraisal`, `accruals`, `arrangement` | The ISAD(G) elements of the same names | Same |
| `access_conditions`, `reproduction_conditions`, `physical_characteristics`, `finding_aids` | The ISAD(G) elements of the same names | Same |
| `location_of_originals`, `location_of_copies`, `related_units_of_description` | The ISAD(G) elements of the same names | Same |
| `languages[]`, `scripts[]` (ISO 639 and ISO 15924 lists) and `language_notes` | Language(s) and Script(s) of material, chosen from ISO 639 and ISO 15924, and Language/scripts of material as the note | Same, from v0.0.73; EAD, MODS and Dublin Core carry the note only until phase 11 |
| `notes[type, content]`, `publicationNotes`, `archivistNotes` | Note, Publication note, Archivist's note | Same; note types missing |
| `relatedDescriptionsSearch` | Associated record relation | Modelled |
| `subjectAccessPoints`, `placeAccessPoints`, `genreAccessPoints`, `nameAccessPoints` | Subject, place, documentary form and subject (agent) relations | Modelled |
| `childLevels[]` (add lower levels from the form) | Add new child levels: identifier, level and title, three rows a save | Same, from v0.0.74 |
| `description_identifier` | Description identifier | Same |
| `institution_responsible_identifier` | Institution identifier | Same, from v0.0.73 |
| `rules`, `description_status_id`, `description_detail_id`, `revision_history` | Rules or conventions, Status, Level of detail, Dates of descriptions | Same |
| `languagesOfDescription[]`, `scriptsOfDescription[]` | Language(s) and Script(s) of description, from ISO 639 and ISO 15924 | Same, from v0.0.73 |
| `sources` | Sources | Same, from v0.0.73 |
| `publication_status_id`, `updateDescendants` | Publication of each value and name, with "and everything inside" | Modelled |
| `display_standard_id`, `update_descendants` | Display standard (ISAD(G), DACS, RAD, Dublin Core, MODS, under AtoM's names) and "Make this selection the new default for existing children"; the description is shown and edited, in the Workspace and the public catalogue, under the standard's own numbers, labels and areas, over the same values | Same, from v0.0.76 |
| DACS `technicalAccess` | Technical access (DACS 4.3) | Same, from v0.0.76 |
| RAD `otherTitleInformation`, `titleStatementOfResponsibility`, `editionStatementOfResponsibility`, `statementOfScaleCartographic`, `statementOfProjection`, `statementOfCoordinates`, `statementOfScaleArchitectural`, `issuingJurisdictionAndDenomination`, `titleProperOfPublishersSeries`, `parallelTitleOfPublishersSeries`, `otherTitleInformationOfPublishersSeries`, `statementOfResponsibilityRelatingToPublishersSeries`, `numberingWithinPublishersSeries`, `noteOnPublishersSeries`, `standardNumber` | The RAD elements of the same names, under RAD's numbers (1.1E to 1.9) | Same, from v0.0.76 |
| RAD `materialTypeIds`, DC `dcTypeIds`, MODS `modsTypeIds` | General material designation, Type (DCMI) and Type of resource, from bundled lists; Dublin Core and MODS export them as dc:type and typeOfResource | Same, from v0.0.76 |
| MODS `publisher_name`, `mods_note` | Publisher, and the Note | Same, from v0.0.76 |
| MODS `creation_date`, `publication_date` (and their start dates) | Dates of the unit, with their date type | Modelled |
| `source_standard` | Source standard, in the description control area | Same, from v0.0.75 |
| `watermark_*` (per description) | Watermark for this description: the institution's, none, or its own type, position and opacity, with making the copies again | Same, from v0.0.75; Heratio's library of custom images is the institution's one image |
| ICIP sensitivity | Traditional Knowledge and Biocultural Labels | Modelled |
| Custom fields (`ahg-custom-fields`) | none | Missing |
| `security_classification_*` | none | Phase 18 |
| `collection_type_id` (GLAM sector) | none | Phase 21 |

: Archival description, field by field

### Authority record (`ahg-actor-manage`)

| Heratio field | Workspace | Status |
|---|---|---|
| `entity_type_id` | Type of entity | Same |
| `authorized_form_of_name` | Authorised form(s) of name | Same |
| `other_names[parallel, standardized, other]` | Parallel, standardised and other forms of name | Same |
| none | Historical and abbreviated forms of name, kinds of other form (Croatia's eArhiv) | Workspace only, from v0.0.105 |
| `corporate_body_identifiers` | Identifiers | Modelled |
| `dates_of_existence` | Dates of existence, with existence, birth and death dates | Same |
| `history`, `places`, `legal_status`, `functions`, `mandates`, `internal_structures`, `general_context` | The ISAAR(CPF) elements of the same names | Same |
| `occupations[occupation, content]` | Occupation relation | Modelled |
| `relatedActors[...]` | Agent relations (hierarchical, temporal, family, associated) | Modelled |
| `relatedResources[...]` | Creation, accumulation and other relations to records | Modelled |
| `parent_id` | Agent hierarchical relation | Modelled |
| `maintaining_repository_id` | Maintained by | Same |
| `description_identifier`, `institution_responsible_identifier` | Description identifier, Institution identifiers | Same |
| `rules`, `description_status_id`, `description_detail_id`, `revision_history`, `sources`, `maintenance_notes` | The ISAAR(CPF) control elements of the same names | Same |
| `language[]`, `script[]` (lists) | Language(s) and Script(s), from ISO 639 and ISO 15924, beside Languages and scripts as a note | Same, from v0.0.73 |
| Contact area | Contact points | Same |
| `embargo_until` | Embargoed until | Same |
| `publication_status_id` | Publication of each value and name | Modelled |
| `source_standard` | Source standard, in the description control area | Same, from v0.0.75 |
| ICIP sensitivity | ICIP cultural-sensitivity classification, Heratio's seven levels as a vocabulary, shown on the record and in the public catalogue | Same, from v0.0.75 |
| Custom fields | none | Missing |

: Authority record, field by field

### Repository (`ahg-repository-manage`)

| Heratio field | Workspace | Status |
|---|---|---|
| `identifier`, `authorized_form_of_name`, `parallel_name`, `other_name` | Identifier, Authorised, parallel and other forms of name | Same |
| `repository_type` (ISDIAH 5.1.5) | Type, from AtoM's repository types | Same, from v0.0.73 |
| `contact_*` (type, primary, person, street, city, region, country, postal code, telephone, fax, email, website) | Contact points | Same |
| `contact_latitude`, `contact_longitude`, `contact_note` | Latitude, Longitude, Note | Same, from v0.0.73 |
| `history`, `geocultural_context`, `mandates`, `internal_structures`, `collecting_policies`, `buildings`, `holdings`, `finding_aids` | The ISDIAH elements of the same names | Same |
| `opening_times`, `access_conditions`, `disabled_access`, `research_services`, `reproduction_services`, `public_facilities` | The ISDIAH elements of the same names | Same |
| `desc_identifier`, `desc_rules`, `desc_status_id`, `desc_detail_id`, `desc_revision_history`, `desc_sources`, `maintenance_notes` | The ISDIAH control elements of the same names | Same |
| `desc_institution_identifier` (ISDIAH 5.6.2) | Institution identifier | Same, from v0.0.73 |
| `desc_language`, `desc_script` (ISDIAH 5.6.7) | Language(s) and Script(s), from ISO 639 and ISO 15924 | Same, from v0.0.73 |
| `geographic_subregion`, `thematic_area` | Geographic subregion, Thematic area, under Access points | Same, from v0.0.73 |
| `upload_limit` | Upload limit (GB), set by who may manage the institution; empty for no limit (AtoM's -1), 0 switches uploads off; refused uploads and the usage on the repository's page | Same, from v0.0.74 |
| Theme (`edit-theme`: logo, banner, colours) | Edit theme: background colour, banner, logo and page content on the public repository page | Same, from v0.0.75; the page content is Markdown with HTML removed, where AtoM takes HTML and inline CSS |
| Custom fields | none | Missing |
| none | Complete reference codes | Workspace only |

: Repository, field by field

### Function (`ahg-function-manage`) and activity

| Heratio field | Workspace | Status |
|---|---|---|
| `type_id` | Type of activity | Same |
| `authorized_form_of_name`, `parallel_name`, `other_name` | Authorised, parallel and other forms of name | Same |
| `classification` (ISDF 5.1.5) | Classification | Same, from v0.0.73 |
| `dates` (ISDF 5.2.1) | Dates | Modelled |
| `description`, `history`, `legislation` | Description, History, Legislation | Same |
| `description_identifier`, `institution_identifier`, `rules`, `description_status_id`, `description_detail_id`, `revision_history`, `language`, `script`, `sources`, `maintenance_notes` (ISDF control area) | The ISDF 5.4 elements of the same names, with Language(s) and Script(s) from ISO lists | Same, from v0.0.73 |

: Function and activity, field by field

### Term (`ahg-term-taxonomy`) and concept

| Heratio field | Workspace | Status |
|---|---|---|
| `taxonomy_id`, `code`, `name` | Vocabulary, Notation, Label in each language | Same |
| `use_for` | Alternative and hidden labels | Same |
| `parent_id`, `narrow_terms`, `related_terms` | Broader, narrower and related concepts | Same |
| `crossMatches[source, target, match type]` | Matches to other schemes | Same |
| `scopeNotes[]`, `sourceNotes[]`, `displayNotes[]` | Scope note, Source note, Display note (with a definition and a history note besides) | Same |
| `converse_term`, `self_reciprocal` (relation-type terms) | Relation type on a concept of a relation-type vocabulary: Narrows (the bundled type it narrows), How it reads, Converse term, in each language; Self-reciprocal follows the bundled type. The bundled types and what they connect are rows, no longer configuration | Same, from v0.0.76 |
| `protocol_*` (ICIP protocol on a term) | Community protocol: access condition, Label, owning community and region; a withholding condition hides the term and every unit of description tagged with it from the public, the API, OAI and accounts that may not change them, and keeps their files back; Labels bring Heratio's default conditions, which a record may override | Same, from v0.0.75; the AI fence waits for phase 17 and the owning community seeing its own material for Heratio's phase 3 |

: Term and concept, field by field

### Physical storage (`ahg-storage-manage`) and container

| Heratio field | Workspace | Status |
|---|---|---|
| `name`, `type_id`, `barcode`, `reference_code` | Name, Type, Barcode and other identifiers | Same |
| `building`, `floor`, `room`, `aisle`, `bay`, `rack`, `shelf`, `position`, `location` | Storage places (building to shelf) with their place types, and Location as written | Modelled |
| `width`, `height`, `depth` | Width, height, depth (cm) | Same |
| `total_capacity`, `used_capacity`, `capacity_unit`, `total_linear_metres`, `used_linear_metres` | Capacity and capacity used, in a unit (linear metres among them) | Same |
| `climate_controlled`, `temperature_min`, `temperature_max`, `humidity_min`, `humidity_max` | The same | Same |
| `security_level`, `access_restrictions`, `status`, `notes` | Security level, Who may open it, Status, Note | Same |
| PSIS `accession_container`: `condition_status`, `weight_kg`, `item_count` | Condition, Weight (kg), Number of items | Same, from v0.0.80 |

: Physical storage, field by field

### Digital object and instantiation

| Heratio field | Workspace | Status |
|---|---|---|
| Upload, `replace_file` | Upload to an instantiation; a new file beside the old | Same |
| `media_type_id` | Carrier type and media type | Same |
| `repFile_reference`, `repFile_thumbnail` (own reference and thumbnail files) | Give your own copy: an image for the reference copy or thumbnail, sized and watermarked as a made one | Same, from v0.0.74 |
| `display_as_compound` | View children as compound? on the record: Yes (the default, as Heratio's) shows the imageflow of the units inside on the public page | Same, from v0.0.75 |

: Digital object, field by field

### Rights (`ahg-extended-rights`)

| Heratio field | Workspace | Status |
|---|---|---|
| `basis`, `rights_statement_id`, `cc_license_id`, `copyright_status` | Basis, Rights statement, Licence, Copyright status | Same |
| `acts[]`, `restrictions[]` | Grants: act and restriction | Same |
| `copyright_status_date`, `statute_determination_date` | Status determined on | Same |
| `copyright_jurisdiction`, `statute_jurisdiction` | Jurisdiction | Same |
| `statute_citation` | Citation | Same |
| `start_date`, `end_date`, `end_date_open`, `copyright_expiry_date` | In force from, until (open when empty) | Same |
| `copyright_holder`, `rights_holder_name` | Rights holder (an authority record) | Modelled |
| `copyright_note`, `statute_note`, `rights_note` | Note | Modelled: one note |
| `restriction_reasons[]` (a reason beside each act) | The note of each grant | Same |
| `license_type`, `license_identifier`, `license_url` | Licence (a concept with its IRI) | Modelled |
| `license_terms` (a licence outside Creative Commons) | Licence terms | Same, from v0.0.73 |
| `policy_identifier` | Policy identifier | Same, from v0.0.73 |
| `donor_name` | Rights holder (an authority record), on an accession's rights as on others | Modelled, from v0.0.80 |

: Rights, field by field

### Access request (`ahg-access-request`)

| Heratio field | Workspace | Status |
|---|---|---|
| `reason`, `justification`, `urgency` | Reason, Justification, Urgency | Same |
| `scope_type` (all, collection, item) | What do you need access to? (Everything, A collection, A single item), a concept | Same, from v0.0.74 |
| `scope_item_id` (an item) | The instantiation asked for, or a record asked for from its page (Heratio searches for it on the form) | Same; the item is chosen from its record, from v0.0.74 |
| `scope_collection_id` (a whole collection) | A collection, chosen from the records at a collection level, with what is inside it | Same, from v0.0.74 |
| `request_type`, `subject`, `description` | Request type (a concept), Subject, Description (kept as the reason, as Heratio does) | Same, from v0.0.74 |
| `access_type` (request-object form, never kept by Heratio) | Access type, a concept, kept | Same, from v0.0.74 |
| `requested_classification_id` (security clearance) | none: a classified record is classified on its own page | Modelled, from v0.0.101 |

: Access request, field by field

### Accession (`ahg-accession-manage`)

Heratio's accession edit form and CAAIS partial (`edit.blade.php`, `partials/_caais-edit.blade.php`) and its show page; the CAAIS numbers are the Workspace's section numbers (ADR 0010).

| Heratio field | Workspace | Status |
|---|---|---|
| The add form (`edit.blade.php` with no accession): every field below on one page | Add new accession record: basic info, an alternative identifier, the acquisition date, the donor or a new donor with their primary contact, the administration area, a date of the material, an event, creators, the CAAIS elements and the descriptions made from it | Same, from v0.0.80; more dates, events, extents, languages and preservation requirements are added on the accession's page |
| `identifier` (Accession number) | Accession number (CAAIS 1.2), unique in the institution | Same, from v0.0.77 |
| `alternativeIdentifiers[identifier, identifierType, note]` | Identifiers of the accession, typed (previous, other) | Modelled: a type in place of a free label; no note |
| `date` (Acquisition date) | Dates: Acquisition date | Modelled |
| `editEvents[type, date, startDate, endDate]` (Date(s)) | Dates (CAAIS 3.1): creation and accumulation | Modelled |
| `source_of_acquisition` | Immediate source of acquisition (CAAIS 2.1) | Same, from v0.0.77 |
| `location_information` | Location information (CAAIS 4.1) | Same, from v0.0.77 |
| `donor_id`, `donor_name` and the related donor dialogue | Donor/Transferring body area: an authority record taking part in the donor role | Modelled |
| `donor_contact_person`, `donor_street_address`, `donor_city`, `donor_region`, `donor_country`, `donor_postal_code`, `donor_telephone`, `donor_fax`, `donor_email`, `donor_url`, `donor_latitude`, `donor_longitude`, `donor_note`, `donor_contact_type` | The donor's contact points on its authority record, the primary one shown on the accession | Modelled |
| `acquisition_type_id` | Acquisition type (CAAIS 1.4) | Same, from v0.0.77 |
| `resource_type_id` | Resource type | Same, from v0.0.77 |
| `title` | Title (CAAIS 1.3) | Same, from v0.0.77 |
| `creators` | Creators: authority records related as creators of the material | Modelled |
| `archival_history` | Archival/Custodial history (CAAIS 2.2) | Same, from v0.0.77 |
| `scope_and_content` | Scope and content (CAAIS 3.3) | Same, from v0.0.77 |
| `appraisal` | Appraisal, destruction and scheduling (CAAIS 4.4) | Same, from v0.0.77 |
| `physical_characteristics` | Physical condition (CAAIS 4.3) | Same, from v0.0.77 |
| `received_extent_units` | Received extent units (CAAIS 3.2) | Same, from v0.0.77 |
| `processing_status_id`, `processing_priority_id` | Processing status (CAAIS 1.6), Processing priority | Same, from v0.0.77 |
| `processing_notes` | Processing notes (CAAIS 6.1) | Same, from v0.0.77 |
| `information_objects[]` (linked descriptions) | Archival descriptions it resulted in, and "Create archival description" | Modelled |
| `caais[repository_id]` | Repository (CAAIS 1.1) | Same, from v0.0.77 |
| `caais[rules_or_conventions]` | Rules or conventions (CAAIS 7.1) | Same, from v0.0.77 |
| Source confidentiality, per source (CAAIS 2.1.6) | Source confidentiality, per donor | Same, from v0.0.77 |
| Extent statements (CAAIS 3.2: type, quantity, ca., unit, content type, carrier type, digital file formats, note) | Extent statements | Same, from v0.0.77 |
| Language of material (CAAIS 3.4: language, statement) | Language of material, from ISO 639, with a statement | Same, from v0.0.77 |
| Preservation requirements (CAAIS 4.3: type, requirement, note) | Preservation requirements | Same, from v0.0.77 |
| Transfer and accessioning events (CAAIS 5.1: type, date, agent, note), and AtoM's Event(s) | Transfer and accessioning events | Same, from v0.0.77 |
| Creation and revision dates and agents (CAAIS 7.2) | Date of creation or revision, from the audit log | Same, from v0.0.77 |
| Accruals, Accrual to | Accruals | Modelled: relations |
| Link physical storage | Physical storage | Modelled: storage relations to containers |
| Deaccession area (`identifier`, `scope_id`, `date`, `description`, `extent`, `reason`) | Deaccessions | Same, from v0.0.77 |
| Date created, Date modified, Source language | Date created, Date modified, Source language | Same, from v0.0.77 |
| CSV export (Identifier, Title, Accession Date, Status, Priority, Created, Updated) | Export CSV, the same columns | Same, from v0.0.77 |
| CAAIS export, one and all, `external` | Export CAAIS (heratio-caais-json/1), one and all, `external` | Same, from v0.0.77 |
| `accession_v2.status` (draft, submitted, under review, accepted, rejected, returned) and PSIS's transitions | Intake status, with Submit for review, Start review, Accept, Reject and Return for revision | Same, from v0.0.79 |
| Finalise (`accepted_at`, with `finalisationBlockers`) | Accept (finalise), which needs a donor agreement documenting the accession when the institution asks for one | Modelled: Heratio looked for an attachment of a category its list does not have |
| `accession_v2.priority`, `assigned_to`, `submitted_at`, `accepted_at`, `rejected_at`, `rejection_reason`, `intake_notes` | Priority, Assigned to, Submitted, Accepted, Rejected, Rejection reason, Intake notes | Same, from v0.0.79 |
| `accession_v2.donor_agreement_id` | The agreements documenting it (9b) | Modelled |
| Intake queue (status, priority, assignee, search, date range, sort) and its statistics | Intake queue, with the same filters and statistics | Same, from v0.0.79 |
| Accession dashboard (total, by status, by priority, added in 30 days) | The statistics at the head of the intake queue | Same, from v0.0.79 |
| `accession_intake_checklist` (label, completed, by, at, order) | Intake checklist: tick, untick, add, remove | Same, from v0.0.79 |
| `accession_intake_template` (name, description, items, default) and the seeded Standard Intake | Checklist templates in the accession settings; Standard Intake given to every institution | Same, from v0.0.79 |
| `accession_timeline` (event type, actor, description, metadata) | Timeline, with notes | Same, from v0.0.79 |
| `accession_attachment` (file, category, description, uploaded by) | Attachments, kept by SHA-256 and scanned through the file store | Same, from v0.0.79 |
| `accession_numbering_sequence` (mask, repository, last sequence, year) and `nextAccessionNumber` | Numbering: a number given when none is typed, from the institution's mask, each sequence starting again each year | Same, from v0.0.79 |
| `accession_config`: `numbering_mask`, `auto_assign_enabled`, `require_donor_agreement`, `default_priority`, `intake_checklist_template_id` | Accession settings | Same, from v0.0.79 |
| `accession_config`: `require_appraisal`, `allow_container_barcodes`, `rights_inheritance_enabled` | Accession settings: accepting needs a completed appraisal; containers get barcodes; rights pass to descriptions made from it | Same, from v0.0.80, defaults as Heratio's install seeds them |
| `accession_config`: `appraisal_template_id` | The default appraisal template | Modelled: a template is marked default |
| `accession_appraisal` (type, appraiser, monetary value, currency, significance, recommendation, summary, detailed notes, appraised at) | Appraisal: the same, the appraiser an authority record | Same, from v0.0.80; Heratio's form is a stub over PSIS's table |
| `accession_appraisal_criterion` (name, score 1 to 5, weight, notes) and the weighted score | Criteria, scored, with the weighted score | Same, from v0.0.80 |
| `accession_appraisal_template` (name, description, criteria, sector, default) and the seeded Archival Appraisal | Appraisal templates in the accession settings; Archival Appraisal given to every institution | Same, from v0.0.80 |
| Finalise with `require_appraisal` (an appraisal that is not pending) | Accept (finalise) needs a completed appraisal when the institution asks | Same, from v0.0.80 |
| `accession_valuation_history` (type, amount, currency, date, valuer, method, reference document, notes) | Valuation, the latest its current value | Same, from v0.0.80 |
| Valuation report (current value per accession, totals by currency, counts by type) | Valuation report | Same, from v0.0.80 |
| `accession_container` (type, label, barcode, location, condition, item count, weight, dimensions, notes) | Containers: storage containers linked to the accession, added from its page | Modelled: one container in storage, not a second list; dimensions and notes on the container |
| `accession_container_item` (lines in a container not yet described) | none: the descriptions placed in the container | Modelled |
| `accession_rights` (basis, holder, start, end, restriction, conditions, grant act and restriction, notes, inherit) | Rights of the accession: the Workspace's rights, with Restriction, Conditions and Passes to the descriptions | Same, from v0.0.80 |
| `accession_rights_inherited` | A description's right copied from the accession's, naming its source | Modelled: a copy, so it can change on its own |
| Print | none: the browser prints the page | Missing |

: Accession, field by field

### Provenance (`ahg-information-object-manage` provenance)

Heratio's provenance page (`provenance/index.blade.php`), its ProvenanceService and ProvenanceController; PSIS's ahgProvenancePlugin before them.

| Heratio field | Workspace | Status |
|---|---|---|
| `provenance_overview`: `provenance_summary` | Provenance Statement | Same, from v0.0.81 |
| `current_status`, `custody_type`, `acquisition_type`, `certainty_level`, `research_status`, `cultural_property_status` | The same, each a vocabulary | Same, from v0.0.81 |
| `acquisition_date`, `acquisition_date_text`, `acquisition_price`, `acquisition_currency` | Acquisition Date, Date (text), Price, Currency | Same, from v0.0.81 |
| `has_gaps`, `gap_description`, `research_notes`, `is_complete`, `is_public` | The same | Same, from v0.0.81 |
| `nazi_era_provenance_checked`, `nazi_era_provenance_clear`, `nazi_era_notes`, `cultural_property_notes` | Nazi-era provenance (checked, result, notes), Cultural property notes | Same, from v0.0.81 |
| `provenance_entry`: `owner_name`, `owner_type`, `owner_actor_id` | Owner Name, Owner Type, Authority record | Same, from v0.0.81 |
| `owner_location`, `owner_location_tgn` | Location, Getty TGN identifier | Same, from v0.0.81 |
| `start_date`, `start_date_qualifier`, `end_date`, `end_date_qualifier` | Start and End Date in EDTF, each with a qualifier; exported as Dates of their own | Modelled: EDTF, not free text |
| `transfer_type` (in Heratio's groups) | Transfer Method, its group in each concept's note | Same, from v0.0.81 |
| `transfer_details`, `sale_price`, `sale_currency`, `auction_house`, `auction_lot` | The same | Same, from v0.0.81 |
| `certainty`, `sources`, `evidence_type`, `evidence_description`, `notes`, `is_gap`, `gap_explanation` | The same | Same, from v0.0.81 |
| `sequence` and resequencing on delete | The order of the chain, with Move up and Move down | Same, from v0.0.81; moving is added |
| Delete an entry | Delete: marked deleted, its IRI kept | Modelled |
| `provenance_document` (type, title, description, date, date text, file, external URL, archive reference, public, entry) | Supporting documents, the file through the file store | Same, from v0.0.81 |
| Timeline (D3) | Timeline: the dated steps in order | Modelled: a list, not a chart |
| Export CSV | Export CSV, Heratio's columns | Same, from v0.0.81 |
| Provenance and chain of custody on the description | Provenance and custody history on the record's page, and on the public page when the overview allows | Same, from v0.0.81; an owner whose authority record is not published is not named in public |

: Provenance, field by field

### Workflow (`ahg-workflow`)

Heratio's workflow screens and WorkflowService; PSIS's ahgWorkflowPlugin where Heratio's are placeholders (my work, team work, timeline, the step forms). Spectrum procedures are out (phase 21).

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_workflow`: `name`, `description`, `scope_type`, `scope_id`, `trigger_event`, `applies_to` | Name, Description, Scope (with its repository or collection), Trigger, Applies to | Same, from v0.0.82 |
| `is_active`, `is_default`, `require_all_steps`, `allow_parallel`, `auto_archive_days`, `notification_enabled` | The same | Same, from v0.0.82; parallel steps and archiving are kept, not yet applied |
| `spectrum_procedure` | none | Phase 21 |
| `ahg_workflow_step`: `name`, `description`, `step_order`, `step_type`, `action_required` | Name, Description, the order (Move up, Move down), Step type, Action required | Same, from v0.0.82 |
| `required_role_id`, `allowed_group_ids`, `allowed_user_ids` | Required role, Roles that may take it, Accounts that may take it | Same, from v0.0.82; groups are roles |
| `required_clearance_level` | Required clearance level | Modelled: kept; records are classified with 18c, from v0.0.101 |
| `pool_enabled`, `auto_assign_user_id`, `escalation_days`, `escalation_user_id`, `notification_template`, `instructions`, `checklist`, `is_optional`, `is_active` | The same; the escalation days set the due date, and the escalation account is told when a task breaches | Same, from v0.0.82 and v0.0.84 |
| `ahg_workflow_task`: `status`, `priority`, `submitted_by`, `assigned_to`, `claimed_at`, `due_date` | The same | Same, from v0.0.82 |
| `decision`, `decision_comment`, `decision_at`, `decision_by`, `checklist_completed`, `previous_task_id`, `retry_count`, `metadata` | The same | Same, from v0.0.82 |
| `escalated_at`, `queue_id` | Escalated, Queue | Same, from v0.0.84 |
| `ahg_workflow_history` (action, from and to status, by, at, comment, metadata, correlation id) | History, on the task, the record and the dashboard | Same, from v0.0.82 |
| `ip_address`, `user_agent` | none: the audit log keeps who did what | Modelled |
| `ahg_workflow_notification` | Mail through the Outbox and its sent-mail log | Modelled |
| Dashboard, My tasks, Pool, View task, Claim, Release, Approve, Reject | The same | Same, from v0.0.82 |
| Assign to user (`assignToUser`), Return and Resubmit (PSIS) | Assign, Return for revision, Resubmit | Same, from v0.0.82 |
| Start workflow (PSIS, with `getApplicableWorkflow`) | Start a workflow on the record's page, and for everything inside it | Same, from v0.0.82 |
| My work and Team work (PSIS) | My tasks; Team work by role, with who has how many | Same, from v0.0.82 |
| Bulk transition, assign, note, priority | Bulk operations on My tasks, the pool and team work | Same, from v0.0.82 |
| `ahg_workflow_queue` (name, slug, description, sort order, active, SLA days, colour) and the eight seeded queues | Queues, the eight given to each institution | Same, from v0.0.84; the icon is not carried |
| Move to queue, bulk move to queue | Queue on the task, and in the bulk operations | Same, from v0.0.84 |
| `ahg_workflow_sla_policy` (name, queue, workflow, warning, due and escalation days, escalation account and action, active) and PSIS's `sla_policies.yml` | Service level policies, seeded from PSIS's file | Same, from v0.0.84; PSIS's business days were off and are not carried |
| PSIS's SLA standing (on track, at risk, overdue, breached), priority factors, apply policy, override due date | Service level on each task and list, the due date from the policy, Due date with a reason | Same, from v0.0.84 |
| `processSlaBreach` and `WorkflowNotifyOverdueCommand`: warnings, overdue notices, escalation (notify lead, notify admin, auto-reassign) | `workspace:workflow:sla`, each morning at 06:30 | Same, from v0.0.84 |
| Overdue page and CSV, queue and user stats | Overdue tasks and its CSV; Queues with the counts by standing, queue and account | Same, from v0.0.84 |
| `ahg_publish_gate_rule` (name, type, entity, level, material type, repository, field, config, message, severity, active, order) and PSIS's six seeds | Publish gates, the six seeded switched off | Same, from v0.0.84; material type waits for phase 21 |
| Gate types field required, not empty, minimum length, digital object, rights, access conditions, workflow completed | The same | Same, from v0.0.84 |
| Gate types security clearance, IIIF ready | Kept; they pass until phases 18 and 14 | Modelled |
| Gate type custom SQL | none: it ran SQL typed into a form | Not copied |
| `ahg_publish_gate_result` | The results of each check, as their history | Same, from v0.0.84 |
| Publish readiness, publish simulate, publish execute with force | Publish readiness with what the public would see; publishing refused on a failed blocker, with an audited override for who manages workflows | Same, from v0.0.84 |
| `workflow_required_for_publish` and `isWorkflowApprovedForPublish` | A description is published only once a workflow for it is approved | Same, from v0.0.84 |
| Diagram, task diagram | Diagram of a workflow, and of a task's place in it | Same, from v0.0.84 |
| Designer and edges (branching steps) | none: steps run in order | Not copied |

: Workflow, field by field

### Donor (`ahg-donor-manage`)

| Heratio field | Workspace | Status |
|---|---|---|
| Donor name (an actor) | An authority record (person, family or corporate body) | Modelled |
| Contact information (contact person, street address, city, region, country, postal code, telephone, fax, email, website, latitude, longitude, note, contact type) | Contact points of the authority record | Modelled |
| Donor browse | Donors: the authority records that took part in an accession as its donor, with how many | Same, from v0.0.77 |
| Donor agreements, reminders, documents, rights and restrictions | Donor agreements (below) | Same, from v0.0.78 |

: Donor, field by field

### Donor agreement (`ahg-donor-manage`, AtoM's `ahgDonorAgreementPlugin`)

Heratio's `donor_agreement` and its child tables, its agreement dashboard, add, edit, view and reminders pages, and its `ahg:donor-reminders` command. A donor agreement is a kind of its own, exported as a rico:Record (ADR 0010).

| Heratio field | Workspace | Status |
|---|---|---|
| `agreement_number` | Agreement number, unique in the institution | Same, from v0.0.78 |
| `agreement_type_id` (`agreement_type`: name, slug, description, prefix) | Agreement type, a concept (the 14 types, with Heratio's prefix in the scope note), exported as the record's documentary form type | Same, from v0.0.78 |
| `agreement_type.color`, `is_active`, `sort_order` | none: the look is not carried; a concept is deprecated instead of made inactive | Modelled |
| `title`, `description` (and `donor_agreement_i18n` `title`, `description`) | Title, Description, in each language | Same, from v0.0.78 |
| `donor_agreement_i18n` `restrictions`, `conditions`, `attribution_text`, `internal_notes` | Restrictions, Conditions, Attribution text, Internal notes, in each language | Same, from v0.0.78 |
| `status` (draft, pending review, pending signature, active, expired, terminated, superseded) | Status, a concept | Same, from v0.0.78 |
| `donor_id`, `actor_id`, `donor_name` | Donor: an authority record that created the agreement in the donor role | Modelled |
| `donor_contact_info`, `institution_contact_info`, `legal_representative_contact` | Donor contact information, Institution contact information, Legal representative contact | Same, from v0.0.78 |
| `repository_id`, `institution_name` | Repository | Modelled: the repository's own name |
| `accession_id`, `donor_agreement_accession` (`is_primary`, `notes`) | Accessions it documents: documentation relations (rico:documents) | Modelled: no primary flag; a note on the relation |
| `information_object_id`, `donor_agreement_record` (`relationship_type`: covers, partially covers, references) | Linked records: relations of the types covers, covers in part and refers to | Same, from v0.0.78 |
| `legal_representative`, `legal_representative_title`, `repository_representative`, `repository_representative_title` | Parties | Same, from v0.0.78 |
| `agreement_date`, `effective_date`, `expiry_date`, `review_date`, `termination_date` | Dates | Same, from v0.0.78 |
| `termination_reason` | Termination reason | Same, from v0.0.78 |
| `has_financial_terms`, `purchase_amount`, `currency`, `payment_terms` | Financial terms, Payment terms | Same, from v0.0.78 |
| `scope_description`, `extent_statement` | Scope of the material, Extent statement | Same, from v0.0.78 |
| `transfer_method`, `transfer_date`, `received_by` | Transfer | Same, from v0.0.78 |
| `general_terms`, `special_conditions` | General terms, Special conditions | Same, from v0.0.78 |
| `donor_signature_name`, `donor_signature_date`, `repository_signature_name`, `repository_signature_date`, `witness_name`, `witness_date` | Signatures | Same, from v0.0.78 |
| `is_template` | A template for new agreements | Same, from v0.0.78 |
| `parent_agreement_id`, `supersedes_agreement_id` | Part of, Supersedes | Same, from v0.0.78 |
| `logo_path`, `logo_filename` | none: the Workspace prints no agreement | Missing |
| `created_by`, `updated_by`, `created_at`, `updated_at`, `donor_agreement_history` | History, from the audit log | Modelled |
| `donor_agreement_right` (right type, permission, conditions, digital, physical, metadata, start and end, donor approval, fee, fee structure, notes) | Rights | Same, from v0.0.78 |
| `donor_agreement_restriction` (type, all or specific materials, box list, start and end, auto release, release date and trigger, override and authority, reason, legal basis, POPIA category, data subject consent, security clearance level, notes, active) | Restrictions | Same, from v0.0.78 |
| `donor_agreement_reminder` (type, subject, description, date, advance days, recurring and pattern, recurrence end, priority, status, snooze, completion, action required) | Reminders, completed, snoozed, cancelled or made active again | Same, from v0.0.78 |
| `donor_agreement_reminder.notification_recipients` (email addresses) | Accounts of the institution that may read agreements; with none, the account that made it | Modelled: never to an outside address or the donor |
| `notify_email`, `is_sent`, `sent_at`, `donor_agreement_reminder_log` | Send by email; sent once for each date; the sent-mail log | Modelled |
| `notify_system`, `action_url` | none: the Workspace has no notification bell; the mail links the agreement | Missing |
| `donor_agreement_document` (type, file, title, description, date, version, signed, signature date, signed by, confidential, access restriction, uploaded by) | Documents, kept by SHA-256 and scanned through the file store | Same, from v0.0.78 |
| `donor_agreement_document.checksum_md5` | none: SHA-256 only | Modelled |
| Agreement dashboard filters (search, status, type, expiring within 7, 30 or 90 days) | The same filters | Same, from v0.0.78 |
| Agreement reminders page | Agreement reminders, active or snoozed first, with their actions | Same, from v0.0.78 |
| Autocomplete of accessions and records on the form | The relation form's search | Modelled |

: Donor agreement, field by field

### Report builder (`ahg-reports` report-builder)

Added on 31 October 2026, after the form audit of that day.

| Heratio field | Workspace | Status |
|---|---|---|
| `name` | Name | Same |
| `description` | Description | Same |
| `data_source` | Kind | Renamed |
| `category` (Archives, Collections, Heritage, Compliance, General), the list grouped by it | Category, a vocabulary row; the list grouped by it, General when none | Same, from v0.0.92 |
| `visibility` (private, shared, public) | Shared with everyone who may read its kind; a share link, for a number of days, for anyone else | Modelled |
| Columns, filters, sort and sections, chosen in the browser | Columns from the elements of the kind's standard, filters, sort and sections | Same |
| Schedule (frequency, day, time, format, recipients) | How often, Day of the week or month, Time, Format, Send to (accounts) | Same |
| Templates | Templates, and Save as a template | Same |
| Versions and history | Keep this as a version, noting | Same |

: Report builder, field by field

### IIIF collection (`ahg-iiif-collection`)

Added on 31 October 2026.

| Heratio field | Workspace | Status |
|---|---|---|
| `name` | Title | Renamed: IIIF's label |
| `description` | Summary | Renamed: IIIF's summary |
| `parent_id` | Inside the collection | Same; never itself or a collection inside it, from v0.0.92 |
| `attribution` | Attribution | Same |
| `viewing_hint` | How a viewer shows it | Renamed: IIIF 3's behavior replaces 2's viewingHint |
| `is_public` | Public | Same |
| none | Language of the title and summary; Date for a timeline (navDate) | Workspace only: IIIF 3 |
| `manifest_uri`, `label` (an item from elsewhere) | Another institution's IIIF manifest or collection URI, and its label in the collection | Same |
| `object_ids[]` (records found by search) | A record's persistent IRI, one at a time | Modelled |
| `include_children[]` | none: a record with parts is itself a IIIF collection of them | Modelled |
| Viewer workspaces | Saved viewer layouts | Same, from v0.0.88 |

: IIIF collection, field by field

### Embargo (`ahg-extended-rights` embargo)

Added on 31 October 2026.

| Heratio field | Workspace | Status |
|---|---|---|
| `embargo_type` | What it withholds | Renamed |
| `reason` | Reason | Same |
| `start_date`, `end_date` | From, until (empty: until lifted) | Renamed |
| `auto_release` | none: an embargo with a last day ends on it; one without lasts until lifted | Modelled |
| `reason_note`, `internal_note` | Note (staff only) | Modelled: one note |
| `embargo_i18n.public_message` | What the public catalogue says while it is in force | Same |
| `review_date`, `review_interval_months`; the embargoes due for review | Review on, Review every (months); the list's Due for review | Same, from v0.0.92 |
| `notify_before_days` | Warn this many days before it ends | Renamed |
| `notify_emails` | none: the warning goes to who imposed it, else to the institution's administrators | Modelled: never to an outside address |
| `lift_reason` | Reason for lifting it | Same |
| `embargo_exception` (user, group, IP range, dates) | Exceptions: an account, a role or an IP range, for a time | Same |
| Expiring embargoes, `days`, `extend_days` | Ending within 30 days; the end date is changed on the embargo | Modelled |
| Batch embargo (`object_ids`, `embargo_type`, `embargo_end_date`) | And on everything inside it, each its own embargo | Modelled: by the tree, not a list of ids |
| `object_id` | The record whose page it is on | Modelled |

: Embargo, field by field

### Retention and disposal (`ahg-records-manage`)

Added on 31 October 2026.

| Heratio field | Workspace | Status |
|---|---|---|
| `schedule_ref`, `title`, `description` | Reference, Title, Description | Same |
| `authority`, `jurisdiction` | Disposal authority, Jurisdiction | Same |
| `effective_date`, `review_date`, `expiry_date` | In effect from, Review on, Expires on | Renamed |
| Schedule approval and versions | Approved by (name and office); Start a new version | Same |
| `class_ref`, `title`, `description` | Reference, Title, Description | Same; description and editing a class from v0.0.92 |
| `retention_period_years`, `retention_period_months` | Years, Months | Same |
| `retention_trigger`, `disposal_action` | From, Then | Renamed |
| `citation` | Citation (the clause of the authority) | Same |
| `review_required` | Review first | Same |
| `is_active` | Active | Same, from v0.0.92 |
| `sort_order` | none: classes keep the order they were added in | Modelled |
| `disposal_confirmation_required` | none: every disposal is approved by someone other than who initiated it, then cleared | Modelled |
| `disposal_class_id`, `retention_start_date` (assign) | Disposal class; Day of the event it runs from | Same |
| none | Due on another day, Why | Workspace only |
| `action_type`, `reason` (initiate) | Action, Reason | Same |
| `transfer_destination` | Transferred to, asked when the transfer is carried out | Modelled |
| `comment`, rejection and cancellation `reason` | Reason, at each step | Same |
| none | Method, Witness (destruction) | Workspace only |
| `decision`, `decision_notes`, `next_review_due_date` | Decision, Notes, Next review | Same |

: Retention and disposal, field by field

### Records management (`ahg-records-manage`)

Added with 13c.

| Heratio field | Workspace | Status |
|---|---|---|
| `rm_fileplan_node` (`parent_id`, `function_object_id`, `node_type`, `code`, `title`, `description`, `disposal_class_id`, `retention_period`, `disposal_action`, `status`, `source_department`, `source_agency_code`, `import_session_id`) | File plan nodes | Same; `function_object_id` is a function or activity of the Workspace; the nested set is not kept, the tree is read from the parents |
| A node's records, by disposal class or identifier prefix | `file_plan_placements`, one node a record | Changed: placed explicitly (Dr Johan Pieterse, 2 November 2026) |
| `rm_fileplan_import_session` (`source_type`, `source_filename`, `department`, `agency_code`, `status`, `total_nodes`, `imported_nodes`, `linked_records`, `errors_json`, `column_mapping_json`) | File plan imports | Same; a folder listing is uploaded, not read from the server |
| `rm_email_capture` (`message_id`, `from_address`, `to_addresses`, `cc_addresses`, `subject`, `sent_at`, `received_at`, `body_text`, `body_html`, `attachment_count`, `eml_storage_path`, `information_object_id`, `fileplan_node_id`, `disposal_class_id`, `capture_source`, `status`) | Captured e-mail | Same, with the attachments listed and the original kept in the object store with its checksum; IMAP and SMTP drop sources not yet |
| `rm_classification_rule` (`name`, `description`, `rule_type`, `match_pattern`, `fileplan_node_id`, `disposal_class_id`, `priority`, `is_active`, `apply_on`) and `rm_classification_log` | Classification rules and their log | Same |
| `record_declaration` (`status`, `declared_by`, `declared_at`, `workflow_task_id`, `notes`) | Declarations | Same; a declared record cannot be deleted outside its disposal |
| `vital_record` (`reason`, `review_cycle_days`, `next_review_date`, `last_reviewed_at`, `last_reviewed_by`, `is_active`) | Vital records | Same |
| `rm_compliance_assessment` (`framework`, `assessment_ref`, `title`, `scope`, `period_start`, `period_end`, `score_total`, `score_max`, `findings_json`, `recommendations_json`, `status`, `signed_off_by`, `signed_off_at`) | Compliance assessments | Same; the checks read the institution's data |
| `rm_compliance_assessment.report_pdf_path` | The assessment's page, to print | Not kept as a file |

: Records management, field by field

### Privacy registers (`ahg-privacy`)

Added on 31 October 2026.

| Heratio field | Workspace | Status |
|---|---|---|
| Officer: `name`, `title`, `email`, `phone`, `jurisdiction`, `registration_number`, `appointed_date`, `is_active` | Name, Office, Email, Telephone, Jurisdiction, Registration with the Regulator, Appointed on, In office | Same |
| none | Deputy Information Officer, Account | Workspace only |
| RoPA: `name`, `purpose`, `lawful_basis`, `data_subjects`, `data_categories`, `recipients`, `retention_period`, `security_measures`, `department`, `jurisdiction`, `next_review_date` | The same | Same |
| `responsible_person`, `assigned_officer_id` | Owner, Information Officer | Renamed |
| `cross_border_safeguards`, `third_countries` | Transfers across borders (POPIA s.72) | Modelled: one field |
| `dpia_required` | Impact assessment required | Same |
| `dpia_completed`, `dpia_date` | none: read from its signed-off impact assessment | Modelled |
| `status` | Submitted, then approved or rejected by someone else | Modelled |
| none | Special personal information, children's information | Workspace only: POPIA s.26, s.34 |
| DPIA: `name`, `processing_activity_id`, `description`, `necessity_proportionality`, `risks_to_subjects`, `measures_to_mitigate`, `residual_risks`, `dpo_opinion`, `dpo_consulted_at` | The same, the officer's opinion and when consulted | Same |
| `status`, `signoff_note`, `step` | Signed off once its measures are recorded; one form | Modelled |
| Consent: `subject_name`, `subject_email`, `purpose`, `consent_date`, `consent_method`, `source` | Data subject, Contact, Purpose consented to, Given on, How it was given | Same |
| `consent_given`, `data_subject_id`, `jurisdiction` | A record is consent given, withdrawn by its step; the data subject by name; the jurisdiction of its processing | Modelled |
| DSAR: `request_type`, `requestor_*`, `description`, `received_date`, `assigned_to`, `jurisdiction` | What is asked, the requester's details, What the request says, Received on, Handled by, Jurisdiction | Same |
| `priority`, `notes` | Priority, Notes | Same, from v0.0.92 |
| PAIA: `paia_section`, `requestor_*`, `description`, `preferred_format`, `received_date`, `fee_status` | Kind of request, the requester's details, Records asked for, Form of access, Received on, the fees and Fees paid | Same |
| `fee_exemption_requested` | Exemption from fees asked for | Same, from v0.0.92 |
| Complaint: `complainant_*`, `complaint_type`, `description`, `date_of_incident`, `assigned_to`, `jurisdiction` | The same | Same |
| Breach: `breach_type`, `severity`, `description`, `detected_date`, `occurred_date`, `data_categories`, `records_affected`, `jurisdiction` | What was affected, Severity, What happened, Detected, Happened, Personal information affected, Data subjects affected, Jurisdiction | Same |
| `cause` | Cause | Same, from v0.0.92 |
| GDPR Article 30 export, compliance autopilot, data inventory | none | Not carried (ADR 0016) |

: Privacy registers, field by field

### Privacy in the catalogue (`ahg-privacy` PII scan, redaction and file screening)

Added on 31 October 2026 with 18b.

| Heratio field | Workspace | Status |
|---|---|---|
| PII scan of an object (`ahg_pii_scan_report`: jurisdiction, hits by type, findings, coverage, status, notes) | Scan for personal information, under a jurisdiction; what was found and where; what it read; the decision | Same, from v0.0.94 |
| `ahg_pii_finding_embedded` (findings in a file's metadata, each with its resolution) | Findings in a file's embedded metadata, among the scan's; the decision is on the record's latest scan | Modelled |
| `field_name`, `redaction_type`, `redaction_pattern`, `reason`, `is_sensitive` | Field, How, Keep (for a partial redaction), Reason, Special personal information | Same, from v0.0.94 |
| `legal_basis_reference` | Legal basis reference | Same, from v0.0.94 |
| `privacy_reason_id`, `redaction_status`, `notes` (the record's profile) | The reason of each field; a record is redacted where a field is | Modelled |
| `decision` (release, withhold), `ground`, `note` | Release (risk accepted or redacted) or withhold, Ground, Why | Same, from v0.0.94 |
| `privacy_screening_repositories`, `privacy_screening_collections` (settings of ids) | Where files must be screened: a repository, or a record and everything inside it | Modelled: rows |
| Visual redaction of image regions | none | Not carried (ADR 0016): needs an image server |

: Privacy in the catalogue, field by field

### Security clearance (`ahg-security-clearance`)

Added on 2 November 2026 with 18c.

| Heratio field | Workspace | Status |
|---|---|---|
| `security_classification`: `code`, `level`, `name`, `description`, `requires_justification`, `requires_approval`, `max_session_hours`, `watermark_required`, `download_allowed`, `print_allowed`, `copy_allowed`, `active` | The same, per institution, Heratio's six seeded | Same, from v0.0.101 |
| `color`, `icon`, `watermark_image` | Colour; no icon or watermark image | Modelled: the look is not carried |
| `security_compartment`: `code`, `name`, `description`, `min_clearance_id`, `requires_need_to_know`, `requires_briefing`, `active` | The same | Same |
| `user_security_clearance`: `user_id`, `classification_id`, `granted_by`, `granted_at`, `expires_at`, `notes` | Account, Level, granted by, when, Until, Notes; nobody clears themselves | Same |
| `user_security_clearance_log`, `security_clearance_history` | Clearance history: granted, changed, revoked, ended | Same |
| `object_security_classification`: `classification_id`, `classified_by`, `classified_at`, `review_date`, `declassify_date`, `declassify_to_id`, `reason`, `handling_instructions`, `inherit_to_children`, `justification`, `active` | Level, by, on, Review, Declassify on, to, Reason, Handling, Inside it too, Justification; only by someone cleared to the level | Same, from v0.0.101 |
| `security_access_request`, `security_access_grant` | Requests for access above one's clearance, granted for hours or denied, and revoked | Same |
| `security_declassification_schedule` | The record's declassification date, run daily | Modelled: one date on the classification |
| `security_access_log`, `security_audit_log` | The audit log | Modelled |
| `security_watermark_log`, `security_retention_schedule`, `security_compliance_log` | none | Not carried: watermarks are phase 14's, retention phase 13b's, compliance 13c's |
| `ahg_mfa_policy`, `ahg_otp_*`, `ahg_webauthn_credential`, `user_totp_secret` | none | Phase 12 |

: Security clearance, field by field

### Transfers to the National Archives (`ahg-narssa`)

Added on 2 November 2026 with 18d.

| Heratio field | Workspace | Status |
|---|---|---|
| `narssa_transfer`: `transfer_reference`, `title`, `description`, `schedule_codes`, `initiated_by`, `item_count`, `total_size_bytes`, `package_path`, `status`, `transmitted_at`, `accepted_at`, `narssa_receipt_reference`, `notes` | Reference (NARSSA-YYYY-NNN), Title, Description, disposal classes, by, records, bytes, the package with its SHA-256, Status, Transmitted, Accepted, Receipt, Notes | Same, from v0.0.101 |
| `narssa_transfer_item`: `information_object_id`, `disposal_action_id`, `archival_reference`, `title_snapshot`, `schedule_code`, `digital_object_count`, `digital_object_bytes` | Record, its disposal, Reference code, Title, Disposal class, files, bytes, and a SHA-256 for each | Same |
| The package written with a shell `tar` | Written by PHP's own archive classes; master files only | Changed |

: Transfers to the National Archives, field by field

### Export permits (PSIS's `ahgSAHRAPlugin`, NHRA s.32)

Added on 2 November 2026 with 18d.

| PSIS field | Workspace | Status |
|---|---|---|
| `application_ref`, `sahra_permit_number`, `issuing_authority` | Application (SAHRA-APP-YYYY-NNNN), Permit number, Issuing authority | Same, from v0.0.101 |
| `applicant_user_id`, `applicant_name`, `applicant_email`, `institution` | The applicant's account | Modelled |
| `supervisor_user_id`, `supervisor_decision_date`, `supervisor_notes` | Approved by (someone other than the applicant, who may change every record), when, Notes | Same |
| `project_title`, `project_description` | Purpose, Description | Renamed |
| `linked_object_id` | The records to be exported, by IRI, any number | Changed |
| none | Destination institution, Country (ISO code) | Workspace only |
| `start_date`, `end_date`, `conditions`, `fee_amount`, `fee_paid`, `fee_receipt` | The same | Same |
| `sahra_submitted_date`, `sahra_reference`, `sahra_decision_date`, `sahra_notes` | Lodged, The authority's reference, Decided, The authority's notes | Same |
| `status` | Draft, Pending approval, Approved, Returned, Lodged, Issued, Declined, Revoked, Expired, Closed: vocabulary rows | Same |
| `sahra_permit_document`: `doc_type`, `original_name`, `mime_type`, `size_bytes`, `uploaded_by` | What it is, the file, kept in the object store with its SHA-256 | Same |
| `nhra_section`, `site_name`, `site_location`, `province`, `sahra_permit_area`, `sahra_permit_report`, `sahra_reviewer` | none | Not carried: archaeology, palaeontology, structures and burial permits (choice 2A) |

: Export permits, field by field

### The National Archives of Zimbabwe (`ahg-naz`) and the Cyber and Data Protection Act (`ahg-cdpa`)

Added on 2 November 2026 with 18e.

| Heratio field | Workspace | Status |
|---|---|---|
| `naz_closure_period`: `closure_type`, `closure_reason`, `start_date`, `end_date`, `years`, `authority_reference`, `review_date` | Type, Reason, From, to, Years, Authority reference, Review; kept as an embargo on the record, and on what is inside it if asked | Modelled, from v0.0.101 |
| `status`, `release_notes`, `released_by`, `released_at` | Released: the embargo lifted, with who, when and why | Modelled |
| `naz_protected_record`: `protection_type`, `protection_reason`, `authority_reference`, `protection_start`, `protection_end`, `review_date`, `status`, `lifted_by`, `lifted_date`, `lifted_reason` | Type, Reason, Authority reference, From, to, Review; lifted as its embargo is | Modelled |
| `naz_research_permit`: `permit_number`, `researcher_id`, `permit_type`, `research_topic`, `research_purpose`, `start_date`, `end_date`, `fee_amount`, `fee_currency`, `fee_paid`, `fee_receipt`, `payment_date`, `approved_by`, `approved_date`, `status`, `rejection_reason`, `restrictions` | The same; approved by someone other than who entered it; active and expired by the daily run | Same |
| `collections_access` | Repositories it opens | Renamed |
| `naz_researcher` | The Workspace's researchers (phase 19b) | Modelled |
| `naz_research_visit` | Reading room bookings (phase 19b) | Modelled |
| `naz_records_schedule`: `schedule_number`, `agency_name`, `agency_code`, `record_series`, `description`, `disposal_action`, `legal_authority`, `access_restriction`, `approved_by`, `approval_date`, `effective_date`, `review_date`, `status`, `notes` | The same | Same |
| `retention_period_active`, `retention_period_semi`, `classification` | Years active, Years semi-active, Records class | Renamed |
| none | A disposal class of the institution's own schedule | Workspace only |
| `naz_transfer`: `transfer_number`, `transferring_agency`, `agency_contact`, `agency_email`, `agency_phone`, `schedule_id`, `transfer_type`, `description`, `date_range_start`, `date_range_end`, `quantity_linear_metres`, `quantity_boxes`, `quantity_items`, `contains_restricted`, `restriction_details`, `accession_number`, `proposed_date`, `actual_date`, `received_by`, `status`, `rejection_reason`, `location_assigned`, `notes` | The same | Same |
| `naz_transfer_item` (series, dates, quantity, format, condition, restriction, the record) | none | Not carried in 18e: the transfer's description and quantities stand for its items |
| `naz_config`, `naz_audit_log` | The settings; the audit log | Modelled |
| `cdpa_controller_license`: `license_number`, `tier`, `organization_name`, `registration_date`, `issue_date`, `expiry_date`, `potraz_ref`, `data_subjects_count`, `status`, `notes` | A privacy register: Licence number, Tier, Licensed organisation, Registered on, Issued on, Expires on, The authority's reference, Data subjects, Status, Notes; expired by the daily run | Same |
| `certificate_path`, `renewal_reminder_sent` | none: no certificate file, no renewal reminder (the column is kept) | Not carried in 18e |
| `cdpa_dpo`: `qualifications`, `hit_cert_number`, `term_end_date`, `form_dp2_submitted`, `form_dp2_date`, `form_dp2_ref` | The officer's Qualifications, Certificate number, Term ends on, Form DP2 on, its reference | Same |
| `cdpa_data_subject_request`, `cdpa_processing_activity`, `cdpa_dpia`, `cdpa_consent`, `cdpa_breach` | The privacy registers, under the CDPA jurisdiction (30 days to answer, 72 hours to notify POTRAZ) | Modelled: one set of registers for every jurisdiction |
| `cdpa_config`, `cdpa_audit_log` | The jurisdiction's row; the audit log | Modelled |
| none | Each pack switched on or off in the settings | Workspace only |

: The National Archives of Zimbabwe and the CDPA, field by field

### The OCFL object store (`ahg-ocfl`)

Added on 4 November 2026 with 15b.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_ocfl_object_map`: `information_object_id`, `ocfl_object_id` | The instantiation, whose IRI is its object's id | Modelled |
| `storage_root` | One storage root, the object store | Same |
| `head_version` | The object's own inventory, read when needed | Modelled |
| `created_at`, `updated_at` | Each version's `created` in the inventory | Modelled |

### Federation (`ahg-federation`)

Added on 4 November 2026 with 16a.

| Heratio field | Workspace | Status |
|---|---|---|
| `federation_peer`: `name`, `peer_type`, `base_url`, `api_key`, `description`, `contact_email`, `is_active` | The same, per institution; the type a vocabulary row, the key encrypted | Same, from v0.0.118 |
| `config`, `default_metadata_prefix`, `default_set`, `harvest_interval_hours`, `last_harvest_at`, `last_harvest_status`, `last_harvest_records` | The same, from v0.0.119; the repository harvested records are kept in | Same |
| `oai_identifier` | The OAI-PMH address is the peer's base address | Modelled |
| `federation_harvest_session`: `peer_id`, `started_at`, `completed_at`, `status`, `metadata_prefix`, `harvest_from`, `harvest_until`, `harvest_set`, `is_full_harvest`, `records_total`, `records_created`, `records_updated`, `records_deleted`, `records_skipped`, `records_errors`, `error_message`, `initiated_by` | The same | Same |
| `federation_harvest_log`: `peer_id`, `information_object_id`, `source_oai_identifier`, `harvest_date`, `metadata_format`, `action` | The same, with its session and a note | Same |
| Federation provenance properties on the description (`FederationProvenance`) | A table tying each harvested unit to its peer, OAI identifier and datestamp | Modelled |
| `federation_member`: `name`, `base_url`, `contact`, `share_scope`, `is_self`, `is_enabled` | The same, per institution, with when it was last fetched | Same |
| `federation_union_record`: `member_id`, `record_ref`, `title`, `level`, `dates`, `repository`, `url`, `indexed_at` | The same | Same |
| `peer_type` dspace, alma (`DSpaceConnector`, `AlmaConnector`) | The same | Same |
| `peer_type` atom_local (`AtomElasticsearchConnector`) | Not carried: it reads AtoM's own index | Not carried |
| `oai_heritage`, `oai_ead` harvest formats | Not carried: Dublin Core, and the signed API for Workspace and Heratio peers | Not carried |
| Governance columns: `trust_level`, `rate_limit_seconds`, `allowed_entity_types` | The same, the surfaces a peer may be asked for (search, union, vocabularies) | Same, from v0.0.120 |
| `federation_enabled` | The peer's `is_active` | Modelled |
| Discovery columns: `discovery_status`, `protocol_version`, `declared_surfaces`, `maturity_grade`, `capabilities_json`, `last_probed_at` | The same | Same |
| Governance `require_verified` | On the institution's share settings | Same |
| `trustDossierUrl`, `authenticityUrl` | With the AI compliance pages, phase 17 | Not carried |
| `federation_vocab_sync`: `peer_id`, `taxonomy_id`, `sync_direction`, `sync_enabled`, `conflict_resolution`, `sync_interval_hours`, `last_sync_at`, `last_sync_status`, `last_sync_terms_added`, `last_sync_terms_updated`, `last_sync_conflicts` | The same; the taxonomy the institution's local vocabulary, the peer's named by its identifier | Same |
| `federation_vocab_sync_log`: `started_at`, `completed_at`, `status`, `terms_added`, `terms_updated`, `terms_skipped`, `conflicts`, `error_message`, `initiated_by` | The same | Same |
| `federation_vocab_change`: `taxonomy_id`, `term_id`, `change_type`, `old_value`, `new_value`, `created_by`, `created_at` | The same, for the vocabularies the institution shares, listed for peers | Same |
| `propagated_to_peers` | Peers pull the change list; nothing is pushed | Modelled |
| `federation_term_mapping`: `local_term_id`, `remote_term_id`, `remote_term_name`, `mapping_status` | The concept's exact match to the peer's IRI | Modelled |
| `federation_join_request`: `institution_name`, `contact_name`, `contact_email`, `base_url`, `what_they_share`, `notes`, `status`, `reviewed_by`, `reviewed_at` | The same, per institution, with the review note and the peer approval made | Same |
| `NetworkDirectoryService` | `/open-data/network` | Same |
| `ahg_europeana_export`: `started_at`, `finished_at`, `record_count`, `bundle_path`, `bundle_size_bytes`, `status`, `error` | The same, the bundle in OCFL with its SHA-256, and the since date | Same, from v0.0.121 |
| Settings `europeana_data_provider`, `europeana_country`, `europeana_language`, `europeana_default_rights` | On the institution's share settings | Same |
| `EdmSerializer`: ProvidedCHO, Aggregation, WebResource, Agent, Place, TimeSpan | The same, as the public reads the record | Same |
| `federation_loan_request`: `requesting_member_id`, `holding_member_id`, `item_ref`, `item_title`, `purpose`, `status`, `needed_from`, `needed_to`, `notes`, `decided_by`, `decided_at` | The same, the institution's own unit where it holds the item | Same |
| `LoanAnalyticsService` report | The same figures | Same |
| `pinned_key_fingerprint`, `key_pinned_at` | The same | Same |
| `federation_peer_search`: `search_api_url`, `search_api_key`, `search_enabled`, `search_timeout_ms`, `search_max_results`, `search_priority`, `last_search_at`, `last_search_status`, `avg_response_time_ms` | On the peer itself, one row | Same |
| `federation_search_cache`: `query_hash`, `peer_id`, `results_json`, `result_count`, `created_at`, `expires_at` | The same, with the answer's verification | Same |
| `federation_search_log`: `query_text`, `query_hash`, `user_id`, `peers_queried`, `peers_responded`, `peers_timeout`, `peers_error`, `total_results`, `total_time_ms` | The same, per institution | Same |
| `federation_share_setting`: `share_enabled` | The same, per institution | Same |
| `published_only` | Always: only what is published is shared | Modelled |
| `min_level_id` | The levels shared, named | Modelled |
| `FederationSigner` headers, `/open-data/protocol`, `/open-data/federation` | The same scheme and documents | Same |

### OAIS packages, SWORD and Archivematica (`ahg-preservation`, `qtSwordPlugin`, `ahg-archivematica`)

Added on 4 November 2026 with 15d.

| Heratio or AtoM field | Workspace | Status |
|---|---|---|
| `preservation_package`: `uuid`, `name`, `description`, `package_type`, `status`, `package_format`, `bagit_version`, `object_count`, `total_size`, `manifest_algorithm`, `package_checksum`, `originator`, `submission_agreement`, `retention_period`, `parent_package_id`, `information_object_id`, `created_by`, `built_at`, `validated_at`, `exported_at`, `metadata` | The same, type, status and format as vocabulary rows; the id a UUID | Same, from v0.0.117 |
| `source_path`, `export_path` | The serialised bag's place in its own OCFL object | Modelled |
| `preservation_package_object`: `package_id`, `digital_object_id`, `relative_path`, `file_name`, `file_size`, `checksum_algorithm`, `checksum_value`, `mime_type`, `puid`, `object_role`, `sequence` | The same | Same |
| `preservation_package_event`: `event_type`, `event_datetime`, `event_detail`, `event_outcome`, `event_outcome_detail`, `agent_type`, `agent_value` | The same, with the PREMIS event types and outcomes | Same |
| `am_job`: `object_id`, `direction`, `status`, `am_uuid`, `microservice`, `last_polled_at`, `error`, `payload` | The same, with the transfer, SIP and AIP UUIDs each kept | Same |
| `am_link`: `object_id`, `transfer_uuid`, `sip_uuid`, `aip_uuid`, `dip_uuid`, `am_pipeline_uuid`, `status` | The same, with the files taken in and a note | Same |
| Settings `am_dashboard_*`, `am_ss_*`, `am_default_pipeline_uuid`, `am_transfer_source_location_uuid`, `am_transfer_source_path`, `am_transfer_staging_path` | One row, set by operators, the API keys encrypted | Same |
| `am_dip_match_strategy` uuid, identifier | The AIP's UUID first, then the IRI or identifier in `dc:identifier` | Same |
| `am_dip_match_strategy` slug | No slugs in the Workspace; the IRI instead | Modelled |
| AtoM `qtSwordPlugin` service document, deposit, `X-Packaging`, `Content-Location`, `Content-MD5`, `Content-Disposition` | The same, SWORD 1.3 | Same |
| AtoM SWORD HTTP Basic with the password | An API key with the write scope instead | Modelled |
| AtoM SWORD child descriptions made from a DIP's structMap | The DIP's files kept as instantiations of the unit deposited to | Modelled |

### PREMIS events and file formats (`ahg-preservation`)

Added on 4 November 2026 with 15a.

| Heratio field | Workspace | Status |
|---|---|---|
| `preservation_event`: `digital_object_id`, `information_object_id`, `event_type`, `event_datetime`, `event_detail`, `event_outcome`, `event_outcome_detail`, `linking_agent_type`, `linking_agent_value` | The same; type, outcome and agent type as vocabulary rows matched to the Library of Congress preservation vocabulary | Same, from v0.0.114 |
| `linking_object_type`, `linking_object_value` | The source file of a creation | Modelled |
| `preservation_format`: `puid`, `mime_type`, `format_name`, `format_version`, `risk_level`, `preservation_action`, `is_preservation_format` | The same, identified by Siegfried | Same |
| `extension`, `risk_notes`, `migration_target_id` | The same, from v0.0.116; the extension from the first file found in the format | Same |
| `preservation_normalization_rule`: `source_pronom`, `source_mime`, `purpose`, `target_format`, `target_ext`, `target_mime`, `tool`, `options`, `priority`, `is_active` | The same, purpose and tool as vocabulary rows; Heratio's default rules seeded | Same, from v0.0.116 |
| `preservation_format_conversion`: `digital_object_id`, `source_format`, `source_mime_type`, `target_format`, `target_mime_type`, `conversion_tool`, `tool_version`, `status`, `source_size`, `source_checksum`, `output_size`, `output_checksum`, `conversion_options`, `started_at`, `completed_at`, `duration_ms`, `error_message`, `created_by` | The same, the source and output files by id | Same |
| `source_path`, `output_path` | The source and output files' places in the OCFL object | Modelled |
| `quality_score` | Not kept: nothing measures it | Not carried |
| `preservation_migration_pathway`: `source_puid`, `target_puid`, `migration_tool`, `quality_impact`, `fidelity_score`, `is_recommended`, `is_automated`, `priority`, `notes` | The same, with the target's extension and type | Same |
| `migration_command` | Built from the tool and its options, never kept as text to run | Modelled |
| `preservation_format_obsolescence`: `current_risk_level`, `migration_urgency`, `affected_object_count`, `storage_size_bytes`, `recommended_action`, `recommended_pathway_id`, `last_assessed_at` | Worked out on the formats page from the format's risk, action, files and recommended pathway; `risk_assessed_at` on the format | Modelled |
| `next_assessment_at` | Not kept: a format is assessed when its risk is changed | Not carried |
| `preservation_migration_plan`: `name`, `description`, `source_puid`, `target_puid`, `pathway_id`, `status`, `created_by`, `approved_by`, `approved_at`, `started_at`, `completed_at`, `run_fixity_after`, `scheduled_start` | The same | Same |
| `scope_type`, `scope_criteria` | The repository or the unit and what it includes | Modelled |
| `total_objects`, `objects_queued`, `objects_processed`, `objects_succeeded`, `objects_failed`, `objects_skipped`, `original_size_bytes`, `converted_size_bytes` | Counted from the plan's files and their conversions | Modelled |
| `keep_originals`, `create_preservation_copies` | Always: the master is never replaced, and the migration is a preservation master | Modelled |
| `max_concurrent` | One worker, one file at a time | Not carried |
| `preservation_migration_plan_object`: `plan_id`, `digital_object_id`, `status`, `queued_at`, `started_at`, `completed_at`, `error_message`, `retry_count` | The same | Same |
| `source_path`, `source_size`, `source_checksum`, `output_path`, `output_size`, `output_checksum`, `duration_ms` | On the file's conversion | Modelled |
| `preservation_replication_target`: `name`, `description`, `target_type`, `is_active`, `sync_schedule`, `last_sync_at`, `last_sync_status`, `last_sync_files`, `last_sync_bytes` | The same; local folder or rsync over SSH; the schedule daily, weekly or monthly | Same |
| `connection_config` | Folder, host, user, port and SSH key file, as columns; no password is kept | Modelled |
| `target_type` s3, azure, gcs, sftp | Not yet: each needs its SDK; rsync over SSH covers a second server | Not carried |
| `preservation_replication_log`: `target_id`, `operation`, `status`, `files_total`, `files_synced`, `files_failed`, `bytes_transferred`, `started_at`, `completed_at`, `duration_ms`, `error_message`, `details` | The same; operation sync or verify | Same |
| `preservation_policy`, `preservation_workflow_schedule`, `preservation_workflow_run` | Fixity schedules (12a), the replication targets' and plans' own schedules, and identification and scanning as each file is kept | Modelled |
| `ahg_premis_rights`: `rights_basis`, `rights_granted_act`, `rights_granted_restriction`, `applicable_dates_start`, `applicable_dates_end` | The Workspace's rights and grants (phase 2), exported as PREMIS rights statements | Same, from v0.0.116 |
| `source_xml` | PREMIS rights XML is not imported | Not carried |
| `preservation_object_format` | The file's format, the basis of the match and any warning | Same |
| `preservation_checksum` (md5, sha1, sha256, sha512) | SHA-256 on the file, every digest an event | Modelled |
| `preservation_fixity_check` | The fixity ledger of phase 12a, each check an event | Same |
| `preservation_virus_scan` | The scan on the file, an event | Modelled |
| PremisXmlSerializer: intellectual entity, representation, files, events, agents | The same, valid PREMIS 3.0 | Same |
| `ahg_premis_rights` | none | Not carried in 15a; 15c |

: PREMIS events and file formats, field by field

### Single sign-on and two-factor sign-in (`arOidcPlugin`, `ahg-security-clearance`)

Added on 3 November 2026 with 12b.

| AtoM or Heratio setting | Workspace | Status |
|---|---|---|
| `arOidcPlugin` `providers`: `url`, `client_id`, `client_secret`, `scopes` | A sign-in provider per row, per institution: issuer, client ID, client secret encrypted, scopes | Same, from v0.0.111 |
| `user_matching_source` (oidc-email, oidc-username) | By verified email, or by the provider's subject | Changed: an email matches only when the provider has verified it |
| `auto_create_atom_user` | A request for an account, approved by an administrator | Changed, on Dr Johan Pieterse's choice |
| `set_groups_from_attributes`, `roles_source`, `roles_path`, `user_groups` | The claim holding roles, and which role each value gives; roles added, never taken away | Same |
| `primary_provider_name`, `provider_query_param_name` | A button for each active provider | Modelled |
| `send_oidc_logout`, `logout_redirect_url`, `enable_refresh_token_use` | none | Not carried in 12b |
| `server_cert` | The host's certificate store | Modelled |
| TotpService: secret, QR, confirm, verify with a window of one step, ten recovery codes | The same; RFC 6238 worked in the Workspace; a code never accepted twice; recovery codes as hashes | Same |
| MfaPolicyService: off, optional, required for administrators, required; grace days | The same, per institution, as vocabulary rows | Same |
| WebAuthnService, OtpService (codes by email) | none | Not carried in 12b |

: Single sign-on and two-factor sign-in, field by field

### Fixity and operations (`ahg-integrity`, `ahg-observability`)

Added on 3 November 2026 with 12a.

| Heratio field | Workspace | Status |
|---|---|---|
| `integrity_schedule`: `name`, `scope_type`, `repository_id`, `information_object_id`, `frequency`, `batch_size`, `max_runtime_minutes`, `is_enabled`, `last_run_at`, `next_run_at` | The same; scope and frequency as vocabulary rows | Same, from v0.0.110 |
| `notify_on_failure`, `notify_on_mismatch`, `notify_email` | An account to tell of files changed or missing | Modelled |
| `algorithm`, `cron_expression`, `io_throttle_ms`, `max_memory_mb`, `max_concurrent_runs`, `total_runs` | SHA-256 only; the scheduler; one run at a time; runs counted from their rows | Modelled |
| `description` | none | Not carried in 12a |
| `integrity_run`: `status`, `objects_scanned`, `objects_passed`, `objects_failed`, `objects_missing`, `bytes_scanned`, `triggered_by`, `triggered_by_user`, `error_message`, `started_at`, `completed_at` | The same, the file counts as checked, unchanged, changed and missing | Same |
| `objects_error`, `objects_skipped`, `lock_token` | An error ends the run as failed; `withoutOverlapping` | Modelled |
| `integrity_ledger`: `digital_object_id`, `file_size`, `expected_hash`, `computed_hash`, `outcome`, `duration_ms`, `verified_at` | The same, with the size found beside the size expected | Same |
| `file_path`, `file_exists`, `file_readable`, `hash_match`, `information_object_id`, `repository_id` | The outcome; the file's own instantiation and record | Modelled |
| `integrity_dead_letter`: `failure_type`, `status`, `consecutive_failures`, `first_failure_at`, `last_failure_at`, `last_run_id`, `acknowledged_by`, `acknowledged_at`, `resolution_notes`, `resolved_at` | The same | Same |
| `retry_count`, `max_retries`, `next_retry_at` | Checked again by each run until it passes | Modelled |
| `integrity_retention_policy`, `integrity_legal_hold`, `integrity_disposition_queue`, destruction certificates | Retention schedules, disposal and certificates of phase 13b | Modelled |
| `ahg-observability` metrics: HTTP, queue, database and AI compliance figures, Prometheus via APCu or Redis | The installation's figures read when asked, as a page and as Prometheus text | Changed |
| OpenTelemetry traces, HTTP latency histograms | none | Not carried in 12a |

: Fixity and operations, field by field

### Vendors (`ahg-vendor`)

Added on 3 November 2026 with 22d.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_vendors`: `name`, `slug`, `street_address`, `city`, `province`, `postal_code`, `country`, `phone`, `phone_alt`, `fax`, `email`, `website` | The authority record's names and contact points | Modelled |
| `vendor_code`, `vendor_type`, `registration_number`, `vat_number`, `status`, `approved_by`, `approved_at`, `notes`, `is_preferred`, `is_bbbee_compliant` | The vendor profile; type and status as vocabulary rows | Same, from v0.0.109 |
| `bank_name`, `bank_branch`, `bank_account_number`, `bank_branch_code`, `bank_account_type` | The same, encrypted and kept out of the audit log | Same |
| `has_insurance`, `insurance_provider`, `insurance_policy_number`, `insurance_expiry_date`, `insurance_coverage_amount` | The same | Same |
| `quality_rating`, `reliability_rating`, `price_rating` | The same, 1 to 5 | Same |
| `ahg_vendor_contacts` | Contact points of the authority record | Modelled |
| `ahg_vendor_service_types`: `name`, `slug`, `requires_insurance`, `requires_valuation`, `typical_duration_days`, `is_active` | A vocabulary of service types; what each needs, per institution | Modelled |
| `description`, `display_order` | The concept's definition and vocabulary order | Modelled |
| `ahg_vendor_services`: `hourly_rate`, `fixed_rate`, `currency`, `notes`, `is_preferred` | The same, currency a concept | Same |
| `ahg_vendor_transactions`: every field, `completion_date` among them | The same; status and payment status as vocabulary rows | Same |
| `has_quotes`, `has_invoices`, `has_condition_reports` | The documents filed, by kind | Modelled |
| `ahg_vendor_transaction_items`: `information_object_id`, `condition_before`, `condition_before_rating`, `condition_after`, `condition_after_rating`, `declared_value`, `currency`, `service_description`, `service_completed`, `service_notes`, `item_cost`, `dispatched_at`, `returned_at` | The same, the record a unit of description, ratings as condition grades, currency the transaction's | Same |
| `item_title`, `item_reference` | The record's own title and identifier | Modelled |
| `ahg_vendor_transaction_history` | The same | Same |
| `ahg_vendor_transaction_attachments` | The same, kept in the file store with SHA-256 and scan | Same |
| `ahg_vendor_metrics` | Worked out when shown | Modelled |
| `ahg_contract`, `ahg_contract_type`, `ahg_contract_document`, `ahg_contract_reminder`, `ahg_contract_history` | none | Not carried in 22d |

: Vendors, field by field

### Webhooks and caption tracks (`ahg-api`, `ahg-media-streaming`)

Added on 3 November 2026 with 22c.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_webhook`: `name`, `url`, `secret`, `events`, `entity_types`, `is_active`, `failure_count`, `last_triggered_at` | The same, per institution | Same, from v0.0.108 |
| `user_id` | The account that made it; the institution owns it | Modelled |
| `ahg_webhook_delivery`: `event_type`, `entity_type`, `entity_id`, `payload`, `response_code`, `response_body`, `attempt_count`, `status`, `next_retry_at`, `delivered_at` | The same; event and status as vocabulary rows; the payload names the entity, never its content | Changed |
| `media_caption_track`: `track_type`, `label`, `language_code`, `is_default` | Kind, label, language, shown by default | Same, from v0.0.108 |
| `vtt_content` | The WebVTT file in the file store, with its SHA-256 and cue count | Modelled |
| `is_sdh` | The captions kind | Modelled |
| `source_url`, `active` | none | Not carried in 22c |

: Webhooks and caption tracks, field by field

### Versions, duplicates, own fields and form templates (`ahg-version-control`, `ahg-dedupe`, `ahg-custom-fields`, `ahg-forms`)

Added on 3 November 2026 with 22b.

| Heratio field | Workspace | Status |
|---|---|---|
| `information_object_version`, `actor_version`: `version_number`, `snapshot`, `change_summary`, `changed_fields`, `created_by`, `created_at`, `is_restore`, `restored_from_version` | One table for both, the same fields | Same, from v0.0.107 |
| Snapshot `base`, `i18n`, `access_points`, `events`, `relations`, `custom_fields` | Values, names, dates, identifiers, relations (access points among them), own fields, and where it sits | Same |
| Restore of the record's row, texts and custom fields | Values, names and own fields; dates, identifiers and relations shown, not restored | Same |
| `ahg_duplicate_rule`: `repository_id`, `name`, `rule_type`, `threshold`, `is_enabled`, `priority` | The same; rule types as vocabulary rows | Same, from v0.0.107 |
| `config_json`, `is_blocking` | none | Not carried in 22b |
| `ahg_duplicate_detection`: `record_a_id`, `record_b_id`, `similarity_score`, `detection_method`, `detection_details`, `status`, `reviewed_by`, `reviewed_at`, `review_notes`, `auto_detected` | The same | Same |
| `ahg_merge_log` | The pair keeps the unit kept and what moved; the deprecated IRI keeps the rest | Modelled |
| `custom_field_definition`: `field_key`, `field_label`, `field_type`, `entity_type`, `field_group`, `is_required`, `is_searchable`, `is_visible_public`, `is_visible_edit`, `is_repeatable`, `include_in_export`, `default_value`, `help_text`, `validation_rule`, `sort_order`, `is_active` | The same | Same, from v0.0.107 |
| `dropdown_taxonomy` | A vocabulary of the institution | Changed |
| `custom_field_value`: `value_text`, `value_number`, `value_date`, `value_boolean`, `value_dropdown`, `sequence` | The same, a dropdown value a concept | Same |
| `ahg_form_template`: `name`, `description`, `form_type`, `is_default`, `is_active`, `version` | The same | Same, from v0.0.107 |
| `ahg_form_field`: `field_name`, `label`, `help_text`, `default_value`, `validation_rules` (required), `section_name`, `sort_order` | The same, the field an element or an own field | Same |
| `field_type` heading and divider, `tab_name`, `placeholder`, `options_json`, `autocomplete_source` | Sections; the element's or own field's own kind | Modelled |
| `ahg_form_assignment`: `repository_id`, `level_of_description_id`, `collection_id`, `priority`, `inherit_to_children`, `is_active` | The same | Same |

: Versions, duplicates, own fields and form templates, field by field

### Intake (`ahg-ingest`, PSIS's `ahgIngestPlugin`, `ahg-scan`, `ahg-ftp-upload`)

Added on 3 November 2026 with 22a.

| PSIS or Heratio field | Workspace | Status |
|---|---|---|
| `ingest_session`: `title`, `standard`, `repository_id`, `parent_id`, `parent_placement` | The import: file name, format, repository, inside the record set, CSV hierarchy by legacyId and parentId | Same |
| `entity_type` (description, accession), `sector` | The import's format (descriptions, authority records, repositories, EAC, RiC-O); archives only | Modelled |
| `security_classification_id` | Classify what it creates | Same, from v0.0.106 |
| `output_create_records`, `output_generate_sip`, `output_generate_aip`, `output_generate_dip` | Records are made on commit; the import as a BagIt bag | Modelled: BagIt in place of OAIS packages |
| `derivative_thumbnails`, `derivative_reference` | Viewing copies made for every file kept | Same |
| `process_ner`, `process_ocr`, `process_summarize`, `process_spellcheck`, `process_translate`, `process_face_detect`, `process_format_id` | none | Phase 17 |
| `process_virus_scan` | The file store's virus scanner (`WORKSPACE_VIRUS_SCANNER`) | Same |
| `status` (configure to commit) | Uploaded, mapped if need be, dry run, committed, undone | Modelled |
| `ingest_file` (csv, zip, ead, directory) | The upload; a ZIP kept for its files | Same |
| `ingest_mapping`: `source_column`, `target_field`, `is_ignored`, `default_value`, `transform` | The mapping: heading, column, left out, value when empty, transform | Same, from v0.0.106 |
| saved mapping profiles | Mappings kept by name | Same |
| `ingest_validation`, `ingest_row` | The dry run's report, entry by entry | Modelled |
| Rollback | Undo, keeping what was changed since | Changed |
| Grid entry | Grid entry under a record set | Same |
| `ingest_watch_folder`: `watch_path`, `label`, `config`, `user_id`, `is_enabled`, `last_scan_at`, `last_status`, `files_ingested` | Folder under the intake root, Name, the folder's import settings and mapping, Working as, Watched, last look, its outcome, files taken | Same, from v0.0.106 |
| Heratio scan sidecar (`heratioScan`: title, identifier, levelOfDescription, scopeAndContent, accessConditions, dates, parentIdentifier) | Read as Heratio writes it | Same |
| `sector`, `standard`, `rights`, `digital_object` options, `dam_augmentation`, `custom_fields`, `merge` in the sidecar | Not read | Not carried in 22a |
| Scan inbox | The intake log | Same |
| Scan API sessions (create, upload, commit, abandon) | One request with the item's files | Changed |
| FTP upload to a remote server (`FtpService`) | An FTP or SFTP account kept to a watched folder | Changed |

: Intake, field by field

### Labels (`ahg-label`)

Added on 3 November 2026 with 22a.

| Heratio field | Workspace | Status |
|---|---|---|
| `name`, `page_size`, `columns`, `rows`, `label_width_mm`, `label_height_mm`, `margin_mm`, `gutter_mm`, `font_size_pt` | The same | Same, from v0.0.106 |
| `show_title`, `show_identifier`, `show_repository`, `show_barcode`, `show_qr`, `is_default` | The same | Same |
| `barcode_source` (identifier, accession, call number, ISBN) | Identifier, reference code, container barcode, persistent IRI | Modelled: library numbers are not an archive's |
| `qr_target` (url, identifier) | Its page (the IRI), its identifier | Same |
| Barcodes from barcodeapi.org | Code 128 drawn by the Workspace | Changed |

: Labels, field by field

### Favourites (`ahg-favorites`)

Added with 19a.

| Heratio field | Workspace | Status |
|---|---|---|
| `archival_description_id`, `object_type` (record, actor, repository, accession, donor, rights holder, term, function, physical object, research objects) | Any entity of the Workspace | Changed: favourites are of catalogue entities; research is gathered in collections and projects |
| `archival_description`, `slug`, `reference_code` | Read from the entity as its owner may read it | Modelled |
| `url` (custom link) | Web address and title | Same |
| `notes` | Notes | Same |
| `folder_id` | Folder | Same |
| `completed_at`, `last_viewed_at` | Last viewed | Modelled: no "completed" flag on a favourite |
| `favorites_folder.name`, `description`, `parent_id` | Name, Description, Inside the folder | Same |
| `color`, `icon`, `sort_order` | none: the look is not carried; folders by position and name | Modelled |
| `visibility` (private, shared, public) | Private, Shared (link), Public, vocabulary rows | Same |
| `share_token`, `share_expires_at` | A link shown once, kept as a hash; For this many days | Same |
| `favorites_share` (via, token, expires, accessed, access count) | The folder's link, its visits and last visit | Modelled: one link a folder |
| Export: CSV, JSON, BibTeX, RIS, EAD, PDF, print | The same, and EndNote XML and CSL-JSON | Same |
| Import (slugs) | Import (persistent IRIs) | Modelled |
| Send to collection, project, bibliography | From the favourites list, to one's collections, projects and bibliographies | Same |

: Favourites, field by field

### Cart and shop (`ahg-cart`)

Added with 19a.

| Heratio field | Workspace | Status |
|---|---|---|
| `cart.user_id`, `session_id`, `archival_description_id`, `product_type_id`, `quantity`, `notes` | The cart, an account's or a visitor's | Same |
| `cart.unit_price`, `completed_at` | The price from the product type; a checked-out cart is emptied | Modelled |
| `ahg_ecommerce_settings` (`is_enabled`, `currency`, `vat_rate`, `vat_number`, `terms_conditions`) | Shop settings | Same |
| `payfast_merchant_id`, `payfast_merchant_key`, `payfast_passphrase`, `payfast_sandbox` | PayFast, the key and passphrase encrypted | Same |
| `stripe_*` | none | Not carried: Heratio never charges with Stripe |
| `admin_notification_email`, `confirmation_email_template` | none: orders are listed for who manages the shop, and the buyer's page shows the order | Modelled: never to an outside address |
| `ahg_product_type` (`name`, `description`, `is_digital`, `requires_shipping`, `is_active`, `sort_order`) and its nine rows | Products, seeded with the nine | Same |
| none | Delivers the file (master, reference or thumbnail) | Workspace only |
| `ahg_product_pricing` (`repository_id`, `product_type_id`, `price`, `price_includes_vat`, `is_active`) | Price, the institution's or a repository's | Same |
| `ahg_order` (`order_number`, `status`, `subtotal`, `vat_amount`, `total`, `currency`, customer, billing and shipping address, `notes`, `paid_at`, `completed_at`, `cancelled_at`) | Orders, ORD-YYYYMMDD-NNNN, statuses as vocabulary rows | Same |
| `ahg_order_item` (record, product, `quantity`, `unit_price`, `line_total`) | Order lines | Same |
| `download_url`, `download_expires_at`, `download_count`, `ahg_download_token` | Downloads of a paid item, for some days and times | Same, kept as hashes |
| `ahg_payment` and `ahg_payment_notifications` | Payments, each notification kept with why it was refused | Same |
| Request to publish (`request_to_publish`: name, surname, email, institution, planned use, motivation, need by) | An access request to publish, from the account | Modelled |
| Guest checkout | none: checkout needs an account | Modelled |

: Cart and shop, field by field

### Feedback (`ahg-feedback`)

Added with 19a.

| Heratio field | Workspace | Status |
|---|---|---|
| `feed_name`, `feed_surname`, `feed_phone`, `feed_email`, `feed_relationship` | Name, Surname, Telephone, Email, Relationship to the record | Same |
| `feed_type_id` (general, bug report, feature request, content correction, compliment) | Type, vocabulary rows | Same |
| `parent_id` (the record's slug) | The record | Same |
| `name` (subject), `remarks` | Subject, Remarks | Same |
| `status`, `completed_at` | Status (pending, completed), Completed | Same |
| `admin_notes` | Staff notes | Same |

: Feedback, field by field

### Share links (`ahg-share-link`)

Added with 19a.

| Heratio field | Workspace | Status |
|---|---|---|
| `information_object_id`, `issued_by` | The record, Issued by | Same |
| `token` | A link shown once, kept as a hash | Modelled |
| `recipient_email`, `recipient_note` | Recipient, Note | Same |
| `expires_at`, `max_access`, `access_count` | Until, At most this many uses, Uses | Same |
| `revoked_at`, `revoked_by`, `revoke_reason` | Revoked, by whom, why | Same |
| `classification_level_at_issuance` | none | With security clearance (18c) |
| `issuer_download_at_issuance` | Its files may be received, decided again at every use | Modelled |
| `information_object_share_access` (`accessed_at`, `ip_address`, `user_agent`, `action`) | Events, vocabulary rows | Same |
| Settings: default and maximum expiry, retention of links and log | 14 and 90 days (365 for an administrator); a year and 180 days | Same |

: Share links, field by field

### Researchers and the reading room (`ahg-research`)

Added with 19b.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_researcher` (`title`, `first_name`, `last_name`, `email`, `phone`, `affiliation_type`, `institution`, `department`, `position`, `student_id`, `research_interests`, `current_project`, `orcid_id`, `researcher_id_wos`, `scopus_id`, `isni`, `researcher_type_id`, `id_type`, `id_number`) | The same; the email is the account's; affiliation and identity document as vocabulary rows | Same |
| `id_verified`, `id_verified_by`, `id_verified_at` | Identity verified, by whom, when | Same |
| `status`, `rejection_reason`, `approved_by`, `approved_at`, `expires_at`, `renewal_reminder_sent` | Status (vocabulary rows), reason, approval, until, warned | Same |
| `photo_path`, `card_number`, `card_barcode`, `card_issued_at` | Photograph, reader's card number | Same; the card number is its barcode |
| `institution_id`, `timezone`, `preferred_language` | The account's institution and interface language | Modelled |
| `api_key`, `api_key_expires_at` | The Workspace's API keys | Modelled |
| `orcid_verified`, `orcid_access_token` and the other ORCID tokens | `researcher_orcid_links` (the iD proven by signing in at ORCID, tokens encrypted) | 19h, v0.0.99 |
| `research_researcher_audit` | The audit log | Modelled |
| `research_researcher_type` (all its fields) | Kinds of researcher | Same |
| `research_verification` (`verification_type`, `document_type`, `document_reference`, `document_path`, `verification_data`, `status`, `verified_by`, `verified_at`, `expires_at`, `rejection_reason`, `notes`) | Checks of identity and affiliation; `verification_data` as notes | Same |
| `research_reading_room` (all its fields, `floor_plan_path` included) | Reading rooms, and the repository each serves | Same |
| `research_reading_room_seat` (`seat_number`, `seat_label`, `seat_type`, `has_power`, `has_lamp`, `has_computer`, `has_magnifier`, `zone`, `notes`, `is_active`, `sort_order`) | Seats | Same |
| `position_x`, `position_y` (a visual seat map) | none | Not carried: the look is not carried |
| `research_booking` (all its fields) | Bookings | Same |
| `research_seat_assignment` | Seat assignments | Same |
| `research_material_request` (all its fields but triage) | Requests for records | Same |
| `triage_status`, `triage_by`, `triage_at` | none | Not carried: nothing in Heratio sets them |
| `research_request_queue` (all its fields) | Retrieval queues | Same; `color` and `icon` not carried (the look) |
| `research_request_status_history`, `research_request_correspondence` | The status log, messages and staff notes | Same |
| `research_retrieval_schedule` | Retrieval runs | Same |
| `research_walk_in_visitor` (all its fields) | Walk-ins | Same |
| `research_equipment`, `research_equipment_booking`, `research_equipment_maintenance` | Equipment, its bookings and maintenance | Same |
| `research_custody_handoff` (all its fields but `spectrum_movement_id`) | Custody hand-overs | Same; Spectrum movements are not part of the Workspace |

: Researchers and the reading room, field by field

### Reproductions, bibliographies, citations and saved searches (`ahg-research`)

Added with 19c.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_reproduction_request` (`reference_number`, `purpose`, `intended_use`, `publication_details`, `status`, `estimated_cost`, `final_cost`, `currency`, `payment_reference`, `payment_date`, `payment_method`, `invoice_number`, `invoice_date`, `delivery_method`, `delivery_address`, `delivery_email`, `completed_at`, `processed_by`, `notes`, `admin_notes`, `sla_due_date`, `assigned_to`, `closed_at`, `closed_by`, `closure_reason`) | The same; statuses and delivery as vocabulary rows | Same |
| `triage_status`, `triage_by`, `triage_at`, `triage_notes` | none | Not carried: nothing in Heratio sets them |
| `research_reproduction_item` (all its fields; `object_id` and `digital_object_id`) | Copies: the record and, when chosen, its file | Same |
| `research_reproduction_file` (`file_name`, `file_path`, `file_size`, `mime_type`, `checksum`, `download_count`, `download_expires_at`, `download_token`) | Files kept by checksum; the token kept as a hash | Same |
| Reproduction pricing (`config('research.reproduction_pricing')` over Heratio's defaults) | Prices of the institution, seeded with Heratio's | Same |
| `research_bibliography` (`name`, `description`, `citation_style`, `is_public`, `share_token`) | The same; the link kept as a hash | Same |
| `research_bibliography_entry` (all its fields) | Entries | Same |
| `research_citation_log` (`object_id`, `citation_style`, `citation_text`) | The record and format cited | Modelled: the text is made again on demand |
| `research_saved_search` (`name`, `description`, `search_query`, `search_filters`, `result_snapshot_json`, `last_result_count`, `total_results_at_save`, `alert_enabled`, `alert_frequency`, `last_alert_at`, `new_results_count`, `is_public`) | The same | Same |
| `query_ast_json`, `search_type`, `facets`, `citation_id` | The words and filters of the Workspace's search | Modelled |
| `research_search_alert_log` | The alert log | Same; Heratio never writes it |
| `research_print_template` (call slips, paging slips, receipts, badges, labels) | Print templates; the call slip page uses the default | 19j, v0.0.99: text with placeholders, escaped, not HTML run as markup |

: Reproductions, bibliographies, citations and saved searches, field by field

### Collections, annotations, notebooks, the research diary and research workspaces (`ahg-research`)

Added with 19d.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_collection` (`name`, `description`, `is_public`, `share_token`) | Collections; the link kept as a hash | Same |
| `research_collection_item` (`object_id`, `object_type`, `external_uri`, `tags`, `reference_code`, `notes`, `sort_order`) | Items: any entity of the Workspace, or an outside address with its title | Same |
| `research_collection_item.culture` | The interface language shows the entity's labels | Modelled |
| Finding aid of a collection (`getCollectionFindingAidData`) | The finding aid page, to print | Same |
| `research_annotation` (`object_id`, `entity_type`, `collection_id`, `digital_object_id`, `annotation_type`, `title`, `content`, `canvas_id`, `tags`, `visibility`) | Annotations of any entity; the copy of the record and its IIIF canvas | Same |
| `research_annotation.content_format` (text, html) | Plain text | Changed: content is shown as text, never as HTML |
| `research_annotation.target_selector`, `research_annotation_target` (`source_type`, `source_id`, `selector_type`, `selector_json`, `source_url`) | One W3C selector of the annotation, with its kind as a vocabulary row and its source | Same |
| `research_annotation.iiif_annotation_id` | The annotation's address in its W3C AnnotationPage | Modelled |
| `research_annotation.is_private` | The annotation's visibility | Modelled: Heratio keeps both; `visibility` says it all |
| Evidence viewer (a record's notes and the tags of its annotations) | The annotation studio's list and tags | Same |
| `research_notebook` (`title`, `summary`, `cover_object_id`, `sort_order`) | Notebooks; the cover a record | Same |
| `research_notebook_item` (`item_type`, `title`, `body`, `source_object_id`, `saved_search_id`, `ai_output_payload`, `pinned`, `sort_order`) | Items: notes, records, saved searches | Same; AI output written in phase 17 |
| `research_journal_entry` (`entry_date`, `title`, `content`, `entry_type`, `time_spent_minutes`, `tags`, `is_private`, `related_entity_type`, `related_entity_id`) | The research diary | Same; the Workspace writes the automatic kinds, which Heratio never writes |
| `research_journal_entry.content_format` (text, html) | Plain text | Changed: as annotations |
| `research_workspace` (`owner_id`, `name`, `description`, `visibility`, `settings`) | Research workspaces | Same |
| `research_workspace.share_token` | none | Changed: one joins by invitation |
| `research_workspace_member` (`role`, `invited_by`, `invited_at`, `accepted_at`, `status`) | Members and invitations | Same |
| `research_workspace_resource` (`resource_type`, `resource_id`, `external_url`, `title`, `description`, `added_by`, `sort_order`, `added_at`) | Resources | Same |
| `research_workspace_file` (`file_name`, `file_path`, `file_size`, `mime_type`, `checksum`, `checksum_type`) | Files kept with the other research files, by SHA-256 | Same |

: Collections, annotations, notebooks, the research diary and research workspaces, field by field

### Researcher submissions (`ahg-researcher-manage`)

Added with 19e. Heratio's package is completed from PSIS's `ahgResearcherPlugin`.

| Heratio field | Workspace | Status |
|---|---|---|
| `researcher_submission` (`researcher_id`, `user_id`, `title`, `description`, `repository_id`, `parent_object_id`, `source_type`, `source_file`, `include_images`, `status`, `total_items`, `total_files`, `total_file_size`, `return_comment`, `reject_comment`, `published_at`, `submitted_at`) | Submissions; the record to go under by IRI; the exchange file kept as a research file | Same |
| `researcher_submission.workflow_task_id` | The submission's own review steps and its reviewer | Modelled: the Workspace's workflows move catalogue entities |
| `researcher_submission_item` (`parent_item_id`, `item_type`, `title`, `identifier`, `level_of_description`, `scope_and_content`, `extent_and_medium`, `date_display`, `date_start`, `date_end`, `creators`, `subjects`, `places`, `genres`, `access_conditions`, `reproduction_conditions`, `notes`, `repository_name`, `repository_address`, `repository_contact`, `sort_order`) | Items; the level a vocabulary row | Same |
| `researcher_submission_item.reference_object_id`, `reference_slug` | The note's record | Same |
| `researcher_submission_item.published_object_id` | What it became: a record, an authority record or a repository | Same |
| `researcher_submission_file` (`original_name`, `stored_name`, `stored_path`, `mime_type`, `file_size`, `checksum`, `caption`, `sort_order`) | Files kept with the other research files, by SHA-256 | Same |
| `researcher_submission_file.published_do_id` | The instantiation made of it | Same |
| `researcher_submission_review` (`reviewer_id`, `action`, `comment`) | The submission's history | Same |
| Publishing (PSIS's PublishService) | Through the import, with a dry run | Changed: the import writes the records |

: Researcher submissions, field by field

### Research projects and working together (`ahg-research`)

Added with 19f.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_project` (`owner_id`, `title`, `description`, `project_type`, `institution`, `supervisor`, `funding_source`, `grant_number`, `ethics_approval`, `start_date`, `expected_end_date`, `actual_end_date`, `status`, `visibility`, `share_token`, `metadata`) | Projects; `institution` kept as the researcher's institution as written; the link kept as a hash | Same |
| `research_project_collaborator` (`role`, `invited_by`, `invited_at`, `accepted_at`, `status`, `notes`) | Collaborators | Same |
| `research_project_collaborator.permissions` | The role | Modelled: Heratio never reads it |
| `research_project_resource` (`resource_type`, `resource_id`, `object_id`, `external_url`, `link_type`, `link_metadata`, `title`, `description`, `notes`, `tags`, `added_by`, `sort_order`, `added_at`) | Resources; a document kept as a research file | Same |
| `research_project_milestone` (`title`, `description`, `due_date`, `completed_at`, `completed_by`, `status`, `sort_order`) and `research_milestone` (`milestone_type`, `description`, `due_date`, `completed_date`, `status`, `progress_pct`, `deliverable`, `owner_id`, `created_by`) | One table of milestones with the fields of both | Same |
| `research_team_member` (`person_name`, `role`, `affiliation`, `email`, `orcid`, `is_lead`, `contribution_note`, `start_date`, `end_date`, `status`, `created_by`) | The team; the ORCID iD's check digit verified | Same |
| `research_output` (`output_type`, `title`, `authors`, `venue`, `identifier_type`, `identifier`, `identifier_url`, `output_date`, `status`, `notes`, `created_by`) | Outputs | Same; a plan is tied to funding and ethics approvals instead (19h) |
| `research_activity_log` (`activity_type`, `entity_type`, `entity_id`, `entity_title`, `details`) for a project | The project's activity log | Same |
| `research_activity_log.session_id`, `ip_address`, `user_agent` | The Workspace's audit log | Modelled |
| `research_discussion` (`workspace_id`, `project_id`, `parent_id`, `researcher_id`, `subject`, `content`, `is_pinned`, `is_resolved`, `resolved_by`, `resolved_at`) | Discussions | Same |
| `research_evidence_comment` (`collection_id`, `item_id`, `project_id`, `author_id`, `parent_comment_id`, `body`, `status`, `resolved_by`, `resolved_at`) | Comments on the evidence | Same |
| `research_collaboration_presence` (`project_id`, `researcher_id`, `cursor_target`, `user_color`, `last_seen_at`) | Who is looking | Same |
| `research_collaboration_session` | none | Not carried: Heratio's realtime service never writes it |
| `research_comment` (`entity_type`, `entity_id`, `parent_id`, `content`, `is_resolved`, `resolved_by`, `resolved_at`) | Comments on annotations, diary entries and collections | Same; reports with 19i |
| `research_clipboard_project` (`project_id`, `object_id`, `is_pinned`, `notes`) | The clipboard | Same |
| `research_export_log` (`format`, `exported_by`, `exported_at`) | The export log | Same |
| `research_institutional_share`, `research_external_collaborator`, `research_institution` | none | Not carried: nothing in Heratio writes or reads them |
| `project_id` on collections, annotations, journal entries, bibliographies, submissions and workspace files | The project they are in | Same |
| `research_notebook.promoted_to_project_id`, `promoted_at` | The project a notebook was promoted to | Same |

: Research projects and working together, field by field

### The reasoning and analysis of a research project (`ahg-research`)

Added with 19i.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_assertion` (`researcher_id`, `project_id`, `subject_type`, `subject_id`, `subject_label`, `predicate`, `object_value`, `object_type`, `object_id`, `object_label`, `assertion_type`, `status`, `confidence`, `version`) | Claims | Same |
| `research_claim_meta` (`evidence_type`, `confidence_level`, `provenance_kind`, `supporting_sources`, `opposing_sources`, `quotations`, `method_theory_link`, `researcher_notes`, `unresolved_weaknesses`, `ethical_concerns`) | The claim's own fields | Same: one table, not two |
| `research_assertion_evidence` (`source_type`, `source_id`, `selector_json`, `relationship`, `note`, `added_by`) | Evidence | Same |
| `research_hypothesis` (`statement`, `status`, `evidence_count`, `tags`) and `research_hypothesis_evidence` (`source_type`, `source_id`, `relationship`, `confidence`, `note`, `added_by`) | Hypotheses and their evidence | Same |
| `research_contradiction` (`claim_a_id`, `claim_b_id`, `kind`, `signature`, `detail`, `severity`, `status`, `source`) | Contradictions | Same |
| `research_decision_log` (`decision_type`, `summary`, `reason`, `related_ref`, `decided_by`, `decided_at`) | The decision log | Same |
| `research_memory_item` (`researcher_id`, `project_id`, `kind`, `title`, `body`, `source_ref`, `status`, `created_by`) | Research memory | Same |
| `research_analysis_result` (`result_type`, `title`, `source_data_ref`, `source_data_version`, `method`, `code_ref`, `generated_at`, `researcher_decision`, `artifact_path`, `created_by`), `research_analysis_result_claim` (`assertion_id`, `relationship`, `note`), `research_analysis_code` (`kind`, `label`, `body`) | Analysis results, their claims, theme tags and memos; the file kept as a research file | Same |
| `research_snapshot` (`title`, `description`, `hash_sha256`, `query_state_json`, `rights_state_json`, `metadata_json`, `item_count`, `status`, `frozen_at`, `citation_id`) and `research_snapshot_item` (`object_id`, `object_type`, `culture`, `slug`, `metadata_version_json`, `rights_snapshot_json`, `sort_order`) | Snapshots, filled with the project's records | Same; the record's IRI for its slug |
| `research_timeline_event` (`label`, `description`, `date_start`, `date_end`, `date_type`, `source_type`, `source_id`, `position`, `color`) | The timeline | Same |
| `research_map_point` (`label`, `description`, `latitude`, `longitude`, `place_name`, `date_valid_from`, `date_valid_to`, `source_type`, `source_id`) | The map | Same |
| `research_source_assessment` (`object_id`, `source_type`, `source_form`, `completeness`, `trust_score`, `rationale`, `bias_context`, `assessed_at`) | Source assessments | Same |
| `research_entity_resolution` (`entity_a_type`, `entity_a_id`, `entity_b_type`, `entity_b_id`, `confidence`, `match_method`, `status`, `resolver_id`, `resolved_at`, `notes`, `evidence_json`, `proposer_id`) | Entity resolution | Same |
| `research_entity_resolution.relationship_type` | One entity, as proposed | Modelled: only sameAs is ever written |
| `research_cross_fonds_query` (`query_text`, `fonds_ids`, `results_count`, `elapsed_ms`) | The log of searches across fonds | Same |
| `research_field_watch` (`doi`, `title`, `source_ref`, `added_by`, `last_checked_at`) and `research_field_alert` (`alert_type`, `title`, `detail`, `url`, `is_read`, `detected_at`) | Field alerts | Same |
| `research_report` (`title`, `template_type`, `description`, `status`, `metadata`), `research_report_section` (`section_type`, `title`, `content`, `bibliography_id`, `collection_id`, `settings`, `sort_order`), `research_report_template` (`name`, `code`, `description`, `sections_config`, `is_system`) | Reports, sections and templates | Same; content plain text |
| `research_peer_review` (`requested_by`, `reviewer_id`, `status`, `feedback`, `rating`, `requested_at`, `completed_at`) | Peer reviews | Same |
| `research_ric_map` | What describes a project or output in the catalogue | Same |
| `research_statistics_daily` | Counted from the services' tables | Modelled |
| `research_validation_queue`, `research_extraction_job`, `research_extraction_result`, `research_quality_metric` | none yet | With the AI services of phase 17 |
| `research_collaboration_session` | none | Not carried: never written |

: The reasoning and analysis of a research project, field by field

### The studios and research journals (`ahg-research`)

Added with 19g.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_writing_doc` (`title`, `doc_type`, `status`, `created_by`), `research_writing_section` (`heading`, `body`, `sort_order`), `research_writing_version` (`version_no`, `snapshot`, `note`, `created_by`) | The writing studio | Same |
| `research_review_comment` (`assertion_id`, `thread_id`, `author_id`, `body`, `resolved`) | The review studio | Same |
| `research_review_run` (`persona`, `model`, `summary`, `findings`) | none yet | With the AI services of phase 17 |
| `research_submission` (`venue_ref`, `venue_name`, `status`, `manuscript_title`, `submitted_at`, `decision_at`, `doi`, `repository_url`, `notes`), `research_submission_requirement` (`label`, `met`, `note`, `sort_order`), `research_submission_response` (`reviewer_label`, `point`, `response`, `revision_note`) | The publication studio | Same |
| `research_method_template` (`code`, `name`, `discipline`, `description`, `guidance`, `is_active`, `sort_order`) and `research_method_protocol` (`template_code`, `title`, `fields`, `status`) | The method studio; Heratio's eleven templates | Same |
| `research_question_brief` (`current_version`, `status`) and `research_question_brief_version` (its ten fields, `change_reason`) | The question builder | Same |
| `research_argument` (`title`, `central_thesis`) and `research_argument_step` (`slot`, `assertion_id`, `note`, `sort_order`) | The argument builder | Same |
| `research_source_triage` (`source_type`, `source_id`, `triage_category`, `read_status`, `notes`, `updated_by`) | Source triage | Same |
| `research_source_triage.ai_preview`, `ai_preview_at` | none yet | With the AI services of phase 17 |
| `research_journal` (`kind`, `title`, `subtitle`, `issn`, `eissn`, `publisher`, `description`, `aims_scope`, `editor_name`, `editor_email`, `target_journal_id`, `cover_object_id`, `doi`, `status`) | Research journals | Same |
| `research_journal_issue` (`volume`, `number`, `title`, `issue_date`, `description`, `status`, `sort_order`) | Issues | Same |
| `research_journal_article` (`issue_id`, `title`, `authors`, `abstract`, `keywords`, `body_markdown`, `reference_style`, `target_journal_id`, `doi`, `word_count`, `status`, `sort_order`) | Articles | Same |
| `research_journal_article.body_html` | Made from the Markdown when shown | Modelled |
| `research_target_journal` (all its fields) | The directory; Heratio's DHET starter list | Same |

: The studios and research journals, field by field

### Funding, plans, ethics, impact, DOIs and ORCID (`ahg-research`)

Added with 19h.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_funding` (`title`, `funder_name`, `funder_type`, `award_reference`, `amount`, `currency`, `status`, `start_date`, `end_date`, `notes`, `dmp_id`, `owner_id`, `created_by`) | Funding | Same; funder type, currency and status are vocabularies |
| `research_grant_draft` (`funder_template`, `title`, `status`, `created_by`) and `research_grant_section` (`section_key`, `label`, `body`, `sort_order`) | Grant applications | Same; each section keeps its hint |
| Grant templates (Heratio's dropdown metadata) and `DmpService::MADMP_SECTIONS` | `research_template_sections` | Rows, not code |
| `research_grant_call` (`funder`, `title`, `url`, `deadline`, `status`, `notes`, `project_id`) | Funding calls | Same |
| `GrantEngineService::draftSection` | none yet | With the AI services of phase 17 |
| `research_dmp` (`title`, `status`, `funder`, `funder_template`, `language`, `contact_name`, `contact_email`, `owner_id`) and `research_dmp_section` | Data management plans | Same; exported as maDMP |
| `research_ethics` (`title`, `approval_type`, `reference_number`, `committee_name`, `status`, `decision_date`, `expiry_date`, `consent_basis`, `data_sensitivity`, `notes`, `dmp_id`) | Ethics approvals | Same |
| `research_impact_signal` (`submission_id`, `doi`, `signal_type`, `title`, `detail`, `url`, `source`, `detected_at`) | Signs of impact | Same; `output_id` for Heratio's `submission_id` |
| `research_replication_log` (`built_by`, `built_at`) | Replication builds | Same, with the pack's SHA-256, size and what it holds |
| `research_project.doi`, `doi_minted_at` | `research_dois`, one for the project and one an output | Registered at DataCite, not made up |
| `researcher_orcid_link` (`orcid_id`, the tokens, `scope`, `expires_at`, `last_synced_at`, `last_works_count`) | `researcher_orcid_links` | Same; the works themselves kept |

: Funding, plans, ethics, impact, DOIs and ORCID, field by field

### Lectures, training, quotas, notices and the rest (`ahg-research`)

Added with 19j.

| Heratio field | Workspace | Status |
|---|---|---|
| `research_lecture` (`researcher_id`, `type`, `title`, `subtitle`, `summary`, `speaker_name`, `speaker_affiliation`, `scheduled_at`, `location`, `duration_minutes`, `recording_url`, `slides_url`, `curriculum_ref`, `cover_object_id`, `status`, `sort_order`) | Lectures | Same; the cover is a record by its IRI |
| `research_lecture_section` (`heading`, `body_markdown`, `media_url`, `media_type`, `sort_order`) and `research_lecture_resource` (`label`, `url`, `resource_type`, `sort_order`) | Sections and resources | Same; `body_html` made when shown |
| `training_course` (`title`, `description`, `audience`, `language`, `pass_mark`, `status`, `sort_order`), `training_module` (`title`, `lecture_id`, `body_markdown`, `sort_order`) | Courses and modules | Same |
| `training_assessment` (`title`, `pass_mark`, `questions_json`) | The assessment | Same; the right answers stay on the server |
| `training_enrolment` (`user_id`, `learner_name`, `learner_email`, `status`, `score`, `enrolled_at`, `completed_at`), `training_progress`, `training_certificate` (`certificate_no`, `score`, `issued_at`) | Enrolments, progress, certificates | Same; the certificate number is not guessable |
| `research_quota_policy` (`scope`, `scope_key`, `period`, `max_downloads`, `max_storage_bytes`, `soft_warn_pct`, `is_active`, `notes`) | Research quotas | Same |
| Downloads in `research_activity_log` | `research_downloads` | A log of their own |
| `research_notification` (`type`, `title`, `message`, `link`, `is_read`, `read_at`) and `research_notification_preference` (`notification_type`, `email_enabled`, `in_app_enabled`, `digest_frequency`) | Notices | Same; written and read, which Heratio did not |
| `research_inbox_item` (kind, origin, status, title, body, source address, attachment, project) | The inbox | Same |
| `research_document_template` (`name`, `document_type`, `description`, `fields_json`) | Document templates, and the documents filled in from them | Same; Heratio filled in nothing |
| `research_print_template` (`name`, `code`, `template_type`, `description`, `template_html`, `css_styles`, `page_size`, `orientation`, margins, `copies_default`, `variables`, `is_default`, `is_active`) | Print templates | Text with placeholders instead of HTML and CSS; one margin |
| `research_rights_policy` (`target_type`, `target_id`, `policy_type`, `action_type`, `constraints_json`, `policy_json`) and `research_access_decision` | Rights policies on records, and decisions | Records only (Heratio's other targets had no check); guarding the record's files |
| `research_offline_sync_log`, `researcher_offline_sync_targets` | none yet | With the offline catalogue, phase 11c (parked) |

: Lectures, training, quotas, notices and the rest, field by field

### Static pages (`ahg-static-page`)

Added on 4 November 2026 with 20a.

| Heratio field | Workspace | Status |
|---|---|---|
| `static_page`: `id`, `source_culture` | `static_pages`: the same, per institution | Same, from v0.0.123 |
| `slug` (AtoM's slug table) | `static_pages.slug`, unique within the institution | Same |
| `static_page_i18n`: `culture`, `title`, `content` | `static_page_translations`: `language`, `title`, `content` (Markdown, its HTML stripped when shown) | Same |
| `/about`, `/privacy`, `/terms`, `/pages/{slug}` | The same addresses | Same |

### Articles (`ahg-articles`)

Added on 4 November 2026 with 20a.

| Heratio field | Workspace | Status |
|---|---|---|
| `blog_post`: `slug`, `title`, `excerpt`, `body`, `attachments_label`, `author`, `article_group`, `view_count`, `published_at`, `created_by` | `articles`: the same, per institution | Same, from v0.0.123 |
| `status` (draft, published) | The article status vocabulary | Same |
| `cover_image` | A site file kept in OCFL | Modelled |
| `protect_from_reset` | Not carried: the Workspace has no nightly demonstration reset | Not carried |
| `blog_attachment`: `kind`, `group_label`, `title`, `description`, `file_name`, `mime`, `file_size`, `sort_order`, `created_by` | `article_attachments` with its site file; the kind a vocabulary row | Same |
| `blog_attachment.file_path` (the public disk) | The site file's OCFL object, served only while the article is published | Modelled |
| `blog_attachment_kind` dropdown (ten kinds) | The article attachment kind vocabulary | Same |
| `blog_post_link`: `post_id`, `related_post_id`, `description`, `sort_order` | `article_links`: the same | Same |
| `blog_comment`: `author_name`, `body`, `ip`, `user_agent` | `article_comments`: the same | Same |
| `blog_comment.status` (approved, pending, spam) | The article comment status vocabulary | Same |
| Uploaded body images (`storeImage`) | Site files kept in OCFL | Modelled |

### Landing pages (`ahg-landing-page`)

Added on 4 November 2026 with 20a.

| Heratio field | Workspace | Status |
|---|---|---|
| `atom_landing_page`: `name`, `slug`, `description`, `is_default`, `is_active`, `meta_title`, `meta_description`, `css_classes`, `custom_css`, `published_at`, `user_id` | `landing_pages`: the same, per institution, `created_by_id` for `user_id` | Same, from v0.0.123 |
| `layout` 'dashboard' and the dashboard's owner | `owner_id` | Modelled |
| `custom_js` | Not carried: nothing typed into a page runs in a visitor's browser | Not carried |
| `atom_landing_page_block_type`: `machine_name`, `label`, `description`, `category`, `is_container`, `default_config` | The landing page block type vocabulary; containers and defaults in `LandingPages` | Modelled |
| `config_schema` | The defaults' kinds, which `cleanConfig` holds each setting to | Modelled |
| `atom_landing_page_block`: `parent_block_id`, `column_slot`, `title`, `position`, `config`, `css_classes`, `background_color`, `text_color`, `col_span`, `is_visible` | `landing_page_blocks`: the same | Same |
| `container_type` (container, container-fluid, none) | `full_width` | Modelled |
| `padding_top`, `padding_bottom` (Bootstrap classes) | The same, in rem | Modelled |
| `atom_landing_page_version` (never written by Heratio) | The audit log | Modelled |
| `atom_landing_page_audit_log` | The audit log | Modelled |
| Block types: rows of 1, 2 and 3 columns, hero banner, text, image carousel, search box, statistics, browse panels, recent items, holdings list, featured items, repository spotlight, map, header, footer, quick links, copyright bar, spacer, divider | The same 20 | Same |
| Block types: GLAM browser, 2 column row public | Not carried: phase 21's other domains, and the same as the 2 column row | Not carried |
| `BlockDataResolver` | `BlockData`, from what the public catalogue may read | Same |

### Help (`ahg-help`)

Added on 4 November 2026 with 20b.

| Heratio field | Workspace | Status |
|---|---|---|
| `help_article`: `slug`, `title`, `category`, `subcategory`, `source_file`, `body_markdown`, `body_html`, `body_text`, `toc_json`, `word_count`, `sort_order`, `tags` | `help_articles`: the same | Same, from v0.0.124 |
| `is_published` | A guide is published by its file being in `docs/help` | Modelled |
| `related_plugin` | `heratio_source`, the Heratio guide it was adapted from | Modelled |
| Admin categories (`Technical`, `Plugin Reference`) hidden from guests | `staff_only`, by each guide's header | Modelled |
| `help_section`: `heading`, `anchor`, `level`, `body_text`, `sort_order` | `help_sections`: the same | Same |
| `help_article_link`: `article_id`, `related_article_id`, `source` (markdown, manual) | `help_article_links`, with `manual` | Same |
| FULLTEXT search of articles and sections | PostgreSQL full-text search, ranked, with highlighted snippets | Same |
| `config/help-context.php`: routes and paths to slugs | Each guide's `routes` header, exact or by pattern | Modelled |
| `ahg:help-ingest`, `ahg:help-ingest-all` | `workspace:help:ingest`, and read in by itself when the files change | Same |
| System map (`system-map.php`, Cytoscape) | `resources/help/system-map.php`, as a nested list | Modelled |
| System breakdown (`system-breakdown.php`, Cytoscape) | `resources/help/system-breakdown.php`, as a nested list | Modelled |
| Manage links (`/help/article/{slug}/links`) | The same | Same |
| Heratio's 494 articles | 73 adapted for the Workspace's areas; each later phase adds its own | Partial |

### DOIs (`ahg-doi`, `ahg-doi-manage`)

Added on 4 November 2026 with 20c.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_doi`: `information_object_id`, `doi`, `status`, `minted_at`, `minted_by`, `datacite_response`, `metadata_json`, `last_sync_at` | `record_dois`: the same, the state a vocabulary row, with whether it was the test service | Same, from v0.0.125 |
| `doi_url` | `RecordDoi::url()` | Same |
| `ahg_doi_config`: `datacite_repo_id`, `datacite_prefix`, `datacite_password`, `datacite_url`, `environment` | The research services' DataCite account (`research_service_settings`), one per institution | Modelled |
| `ahg_doi_config`: `auto_mint`, `auto_mint_levels`, `require_digital_object`, `default_publisher`, `default_resource_type`, `suffix_pattern` | `doi_settings`: the same, the levels and resource type vocabulary rows | Same |
| `ahg_doi_config.repository_id` (a config per repository) | One per institution; `{repository_code}` in the suffix | Modelled |
| `ahg_doi_queue`: `action`, `status`, `priority`, `attempts`, `max_attempts`, `last_error`, `scheduled_at`, `started_at`, `completed_at` | `doi_queue`: the same, with who asked | Same |
| `ahg_doi_log`: `action`, `status_before`, `status_after`, `details`, `performed_by`, `performed_at` | `doi_log`: the same, with whether it succeeded | Same |
| `ahg_doi_mapping` | Not carried: Heratio's code never reads it; the metadata follows the RiC description | Not carried |
| `ahg_io_funding`: `funder_name`, `funder_identifier`, `funder_identifier_type`, `award_number`, `award_uri`, `award_title` | `record_funding`: the same | Same |
| `ahg_datacite_event`: `dedupe_hash`, `subj_id`, `relation_type_id`, `obj_id`, `obj_id_type`, `source_id`, `payload_json`, `response_status`, `response_body`, `state`, `attempts`, `last_error`, `submitted_at` | `datacite_events`: the same | Same |
| `ahg_actor_identifier` ORCID | Identifier types ORCID iD, ISNI and ROR ID on authority records | Modelled |
| DoiMintedMail, DoiFailedMail | `DoiMail`, to who asked | Same |
| `ahg:doi-events-flush`, `ahg:doi-funding-import`, `ahg:doi-metrics-backfill` | `workspace:doi:events`, `workspace:doi:funding-import`, `workspace:doi:metrics-backfill`, and `workspace:doi:process` | Same |

### Dropdowns (`ahg-dropdown-manage`)

Added on 4 November 2026 with 20d.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_dropdown`: `taxonomy`, `taxonomy_label`, `code`, `label` | A bundled vocabulary (`vocabularies`, `concepts`, `concept_labels`), one Turtle file a list | Modelled |
| `ahg_dropdown.label` and its translations, per culture | `vocabulary_overlays.labels`, the institution's wording by language | Same, for the institution alone |
| `ahg_dropdown.sort_order` | `vocabulary_overlays.sort_order` | Same |
| `ahg_dropdown.is_default` | `vocabulary_overlays.is_default` | Same |
| `ahg_dropdown.is_active` | `vocabulary_overlays.hidden`, in open lists only | Same |
| A new term added | A concept of the institution's own (`concepts.institution_id`) under its base IRI, perhaps narrowing a bundled one | Modelled |
| A term deleted | The institution's own term deprecated | Modelled |
| `ahg_dropdown.color`, `icon` | Not carried: the Workspace's lists show no colours or icons | Not carried |
| `taxonomy_section`, create, rename and delete a taxonomy | Not carried: lists come with the Workspace; an institution's own lists are local vocabularies | Not carried |
| `ahg_dropdown_column_map`, `strict` | The `concept_in_vocabulary` triggers on every column that takes a term | Modelled |
| The `term` and `setting` sources | Not carried: there is no AtoM taxonomy table | Not carried |

### Display (`ahg-display`)

Added on 4 November 2026 with 20d.

| Heratio field | Workspace | Status |
|---|---|---|
| `display_profile`: `code`, `domain`, `is_default`, `is_active`, `sort_order` | `display_profiles.name`, for one level of description or the institution's default | Modelled |
| `display_profile`: `identity_fields`, `description_fields`, `context_fields`, `access_fields`, `technical_fields`, `hidden_fields` | `display_profiles.elements`: the ISAD(G) elements shown, in order, grouped by their area | Modelled |
| `display_profile.field_labels` | `display_profiles.labels`, by element and language | Same |
| `display_profile.available_actions` | `display_profile_actions`, a vocabulary row each | Same |
| `display_profile.thumbnail_size` | `display_profiles.thumbnail_size_id`, a vocabulary row | Same |
| `display_profile.layout_mode` (`detail`, `hierarchy`) | `display_profiles.show_tree` | Modelled |
| `display_profile.thumbnail_position`, `css_class` | Not carried: the public theme places pictures | Not carried |
| `display_object_profile`, `display_object_config` (a profile for each object) | A profile is chosen by the unit's level | Modelled |
| `display_level`: `code`, `parent_code`, `valid_parent_codes`, `valid_child_codes` | The level-of-description vocabulary and its broader terms | Modelled |
| `display_field`: `code`, `field_group`, `isad_element`, `dc_element` | The description-element vocabulary and its areas | Modelled |
| `display_collection_type`, GLAM domains, `spectrum_unit` | Phase 21 (other GLAM domains) | Not yet |
| `display_mode_global`: `display_mode`, `items_per_page`, `sort_field`, `sort_direction`, `show_thumbnails`, `allow_user_override` | `browse_settings` with no account: the same, each choice a vocabulary row | Same |
| `user_display_preference`, `user_browse_settings`: `display_mode`, `items_per_page`, `sort_field`, `sort_direction`, `show_thumbnails` | `browse_settings` for one account | Same |
| `show_descriptions`, `card_size`, `show_facets`, `remember_filters`, `last_filters`, `use_glam_browse` | Not carried: the list always shows a summary, and filters stay in the address | Not carried |
| `information_object_title_sort`: `title_sort`, `identifier_sort`, `start_date_sort`, `end_date_sort` | Sorting by title, reference code, earliest and latest date, done by PostgreSQL directly | Modelled |
| `display_facet_cache` | Not carried: the staff browse has no facet counts to cache | Not carried |
| `/glam/print`, `/glam/exportCsv` (at most 5000) | The browse list with `format=print` or `format=csv`, at most 5000 | Same |

### The code reference (`ahg-functions-docs`)

Added on 4 November 2026 with 20d.

| Heratio field | Workspace | Status |
|---|---|---|
| The `php`, `blade` and `routes` catalogues | The same, read from the code by reflection | Same |
| The `js` and `py` catalogues | Not carried: the Workspace has hardly any JavaScript and no Python | Not carried |
| | Console commands and bundled vocabularies | Added |
| The catalogue files in `/opt/ai/km` and their modification times | Built from the code, cached until a source file changes | Modelled |

### AI provenance and receipts (`ahg-provenance-ai`, `ahg-inference-receipts`)

Added on 5 November 2026 with 17a.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_ai_inference`: `uuid`, `service_name`, `model_name`, `model_version`, `endpoint` | `ai_inferences`: the same, the service a vocabulary row | Same, from v0.0.127 |
| `input_hash`, `input_excerpt`, `output_hash`, `output_excerpt`, `confidence` | The same | Same |
| `target_entity_type`, `target_entity_id`, `target_field` | `subject_table`, `subject_id`, `field` | Same |
| `elapsed_ms`, `user_id`, `occurred_at` | The same, with whether the call was answered and its error | Same |
| `model_manifest`, `guardrail` | `details`, what the model reported | Modelled |
| `standard` | Not carried: the field says what it was for | Not carried |
| `fuseki_graph_uri` and the RDF-star replay | The Inferred-Provenance profile in the RiC-O export, which reaches Fuseki with it | Modelled |
| `signature`, `signer_key_id` (heratio#136) and the receipt chain (`seq`, `prev_hash`, `entry_hash`, `kid`, `alg`, JCS, Ed25519) | One row: `seq`, `prev_hash`, `entry_hash`, `signature`, `kid`, under the audit log's key | Same |
| `ahg_ai_override`: `inference_id`, `reviewer_user_id`, `reason`, `original_value`, `override_value`, `status` | The proposal: `inference_id`, `reviewed_by_id`, `review_note`, `value`, `accepted_value`, `status_id` | Modelled |
| The provenance trace endpoint | `/admin/ai/inferences/{inference}` | Modelled |
| Chain verification | `workspace:ai:verify` and Check every receipt | Same |
| `ahg_ai_settings` for OCR: `ocr_vision_model`, `ocr_vision_fallback_model` | `ai_settings.ocr_model`, `ocr_fallback_model` | Same |
| QuotaService | `ai_settings.monthly_limit`, beside the gateway's own quota | Modelled |
| VisionOcrService, Tesseract cross-check | `App\Ai\Ocr\VisionReader`, `App\Ai\Ocr\Tesseract` | Same |
| HtrService | Once a gateway key lets its answer be checked | Not yet |
| NerService: `/ner/extract`, `entities_v2` (value, type, offsets, score), the PERSON, ORG, GPE and DATE buckets | `App\Ai\Assist::names()`, proposals through the API's path | Same, from v0.0.128 |
| NerService's fallback to a language model | Not carried: names come from one known model | Not carried |
| `ahg_ner_entity` and its review | The proposals queue | Modelled |
| LlmService::summarize and its prompt, `summarizer_min_length`, `summarizer_max_length` | `Assist::summary()`, 30 to 200 words, the model in `ai_settings.chat_model` | Same |
| The AI condition assessment (`ai_condition_service_url`) | `Assist::condition()`, a vision model through the gateway until a trained one has a route (ArchiveHeritageGroup/ahg-ai#5) | Modelled |

### Authority resolution (`ahg-authority-resolution`)

Added on 5 November 2026 with 17b2.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_mention`: `ner_entity_id`, `object_id`, `entity_type`, `state`, `promoted_at` | `authority_mentions`: the proposal (its kind and agent type give the label), the unit, the state a vocabulary row | Modelled, from v0.0.129 |
| `ahg_mention`: `assigned_to_user_id`, `assigned_by_user_id`, `assigned_at`, `workflow_task_id` | `assigned_to_id`, `assigned_by_id`, `assigned_at`, `assignment_note`, and a mail; no workflow task, which is Heratio's path without its workflow | Modelled |
| `ahg_mention_context`: offsets, paragraph offsets, `surrounding_text_before`, `surrounding_text_after`, `ner_model_version`, `real_confidence`, `co_occurring_entities`, `nearby_dates`, `nearby_places`, `role_language_tokens` | The same columns on `authority_mentions`, offsets in characters, with the count of occurrences | Same |
| `ahg_mention_candidate`: `rank_position`, `candidate_source`, `candidate_authority_id`, `candidate_display_name`, `name_similarity_score`, `evidence_signals`, `evidence_data`, `composite_score` | `authority_candidates`: the same, the candidate an agent or a place | Same |
| `candidate_fuseki_uri` and the Fuseki adapters | Not carried: the Workspace's triplestore is fed from these records | Not carried |
| `ahg_mention_decision`: `decision_type`, `chosen_candidate_id`, `chosen_authority_id`, `original_system_top_score`, `archivist_user_id`, `decided_at`, `evidence_snapshot`, `candidates_visible_snapshot` | `authority_decisions`: the same, the decision a vocabulary row, append-only, with its reason | Same |
| `fuseki_graph_uri` and DecisionProvenanceWriter | The decision row, and the relation's assertions in the RiC-O export (a model's assertion, reviewed) | Modelled |
| `ahg_mention_park`: `parked_by_user_id`, `parked_at`, `reason`, `new_candidate_available`, `new_candidate_check_at` | `authority_parks`: the same, with the candidates' fingerprint | Same |
| `ahg_ner_feedback`: source text, value, type, offsets, reason, user, model, `training_exported` | `ner_feedback`: the same, `exported_at` | Same |
| `ahg_authority_lookup_cache`: `source`, `entity_type`, `query_text`, `payload`, `license_note`, `retrieved_at`, `ttl_seconds` | `authority_lookup_cache`, the source a vocabulary row | Same |
| `lookup.*.enabled`, `rate_limit`, `cache_ttl`, `license_note`, `license_url`, `lookup.geonames.username`, `lookup.precedence`, `lookup.http_timeout` | `authority_lookup_settings` per institution and source; licences with the source | Same |
| `authority_resolution.candidate_top_n`, `role_language_tokens` | `authority_settings`, per institution; the words of role with Afrikaans added | Same |
| FieldProvenanceWriter | `authority_field_sources`, and the new record's ISAAR(CPF) sources | Modelled |
| AuthorityCreator (ISAAR(CPF) name, dates, history required) | Decisions::createNew, a RiC agent or place | Same |
| Commands: promote-sample, generate-candidates, score-evidence, reprocess, reprocess-parked, scan-parked, export-ner-feedback, cache-clear, cache-stats, status, write-provenance | `workspace:authority:promote`, `rescore`, `scan-parked` (nightly), `export-feedback`, `cache-clear`, `status`; write-provenance not needed | Modelled |
| The review, queue, park, create-new and settings screens | The same, rendered without script | Same |

### Translation (`ahg-translation`)

Added on 5 November 2026 with 17c.

| Heratio field | Workspace | Status |
|---|---|---|
| `ahg_translation_draft`: `object_id`, `field_name`, `source_culture`, `target_culture`, `source_text`, `translated_text`, `status`, `created_by_user_id`, `applied_at` | A proposal of the review queue: the unit, the element, its language, the translation, its status, who proposed it and who reviewed it, and its receipt | Modelled, from v0.0.130 |
| `uk_draft_dedupe` (`source_hash`) | The same translation already waiting is not proposed again; an element already in the target language is left | Modelled |
| `ahg_translation_log` | The log of inferences (service translate) | Modelled |
| `ahg_translation_settings`: `mt.endpoint`, `mt.timeout_seconds`, `mt.api_key` | The AI gateway's address, timeout and key (`workspace.ai`) | Same |
| `translation_target_lang`, `translation_fields` | `ai_settings.translation_target`, `translation_elements` | Same |
| `translation_overwrite`, `translation_save_culture` | Not carried: a translation is always proposed in its own language, and never overwrites without review | Not carried |
| LanguageOptions::translationTargets | `workspace.ai.translation_targets`, the same 21 languages | Same |
| `ui_string`: `key`, `culture`, `value` | `interface_strings`, per institution | Modelled |
| `ui_string_change`: `locale`, `key_text`, `old_value`, `new_value`, `status`, `submitted_by_user_id`, `reviewed_by_user_id`, `reviewed_at`, `review_note` | `interface_string_changes`, the status a vocabulary row | Same |
| DbAwareLoader | `App\Translation\OverlayLoader`, by institution | Same |
| UiStringService: matrix, set, submit, apply, reject, history, pending | `App\Translation\InterfaceStrings` | Same |
| The strings' machine suggestion (`mt-suggest`) | `translation.suggest` | Same |
| `cco` values and the ICIP sensitivity badges | Not carried: museum values are phase 21, and ICIP protocols are in phase 2 | Not carried |
| `translate:import-json` | Not needed: the shipped strings are the JSON files, read directly | Not carried |

### Search by meaning (`ahg-semantic-search`, part one)

Added on 5 November 2026 with 17d1.

| Heratio field | Workspace | Status |
|---|---|---|
| The Qdrant collection of record vectors | `search_vectors`: one vector of 768 dimensions per search entry (public and staff), with its model and text hash, under an HNSW index | Modelled, from v0.0.131 |
| VectorSearchService, `/api/search/semantic` | SearchService mode `meaning`, and `both` by reciprocal rank fusion | Modelled |
| `ahg_thesaurus_term`, `ahg_thesaurus_synonym`, `ahg_thesaurus_sync_log`, syncTerms | The SKOS preferred and alternative labels of the institution's descriptive vocabularies | Modelled |
| `ahg_thesaurus_embedding` | `search_vectors` | Modelled |
| SemanticSearchService::expandQuery (`semantic_min_weight`, `semantic_expansion_limit`) | `Search\Expansion::synonyms`, `search_settings.expansion_limit` | Modelled |
| QueryExpansionService (model, prompt, parseTerms) | `Search\Expansion::fromModel`, Heratio's prompt and parser | Same |
| HistoryRerankService (`ahg_search_query_log`) | `Search\HistoryRerank`, the account's own `search_history`, the last 25, kept only while reranking is on | Modelled |
| RelatedRecordsService | `Meaning::related`, on the public and the staff page | Same |
| GraphGroundingService, `/api/ric/ground` | `Api\Grounding`, `/ground` in the RiC API | Same |
| ThemeService, PlaceService, PersonService, GenreService, TimelineService, ExploreCollectionController | `Discovery\Explore`, `/discover/ways-in` | Same |
| `ahg_semantic_search_settings`, the config page | `search_settings`, on the AI page | Same |
| `ahg_semantic_search_log`, the search and sync logs | The search statistics (phase 6) and the log of inferences | Modelled |
| The saved search templates | The saved searches of phase 19c | Modelled |
| DiscoveriesController (scholarship discoveries) | 17d4, with scholarship | Not yet |
| KmExportGraphCommand, GraphKmBridgeService | Not carried: KM reads the Workspace through its public API and /ground | Not carried |

### Repatriation (`ahg-semantic-search`, part two)

Added on 5 November 2026 with 17d2.

| Heratio field | Workspace | Status |
|---|---|---|
| `displaced_heritage_claim.item_ref` | `repatriation_claims.record_resource_id`, a foreign key in the institution | Modelled, from v0.0.132 |
| `claimant_community` | Claimant community or nation, and its authority record (`claimant_agent_id`) | Same, and modelled |
| `origin_place` | Place / region of origin, and its place (`origin_place_id`) | Same, and modelled |
| `current_holder` | Current holder, the description's repository when left empty | Same |
| `claim_status` (registered, under_review, acknowledged, virtual_return, returned, disputed) | Stage of dialogue, the repatriation-claim-status vocabulary | Same |
| `evidence_summary`, `contact`, `notes`, `created_by` | Documented evidence, Contact, Notes, the account | Same |
| The email found in `contact` | Contact email, its own field | Modelled |
| `repatriation_claim_status_log` | `repatriation_status_changes`, the first status too | Same |
| `repatriation_claim_message` (author_role, author_name, author_user, access_id, body, visibility) | `repatriation_messages`, the party and visibility vocabularies | Same |
| `repatriation_claim_access` (token, grantee_name, grantee_role, can_message, status, expires_at, last_seen_at) | `repatriation_shares`: the token's SHA-256, revoked_at for the status | Modelled |
| `repatriation_knowledge_contribution` (claim, item, type, body, source, contributor_name, credit_consent, contributed_by, moderation_status, moderated_by, moderated_at) | `repatriation_knowledge`, the claim's institution, the knowledge type and status vocabularies | Same |
| The `repatriation_notifications` setting | `repatriation_settings.notify` | Same |
| RepatriationNotifier (in-app to administrators, mail to the claimant) | Mail to those who manage claims, and to the claimant | Modelled |
| The public dashboard and `/repatriation.json` | `/discover/repatriation` and its JSON, where the institution shows its register | Modelled |
| `/virtual-return/{id}` | `/discover/repatriation/{id}`, for a published description, where the institution shows its register | Modelled |
| PublicClaimLodgeController (from a traced object) | Lodge a repatriation claim, from any published description, where the institution allows it | Modelled |
| VirtualReturnSpaceService, the 3D walk | Phase 21, with the exhibitions | Not yet |
| The prefill from DisplacedHeritageService | 17d3, with the register | Not yet |

