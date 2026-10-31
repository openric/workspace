# Heratio parity register

Where each Heratio package stands in the OpenRiC Workspace. The end product has Heratio's functionality on the Workspace's RiC-native model (ROADMAP.md, Next phases); a package is at parity when its functions work here, whether its code was ported, written fresh or replaced by something the Workspace already does. Status is an assessment of 26 October 2026, against heratio-dev's 120 general packages (`ahg-mva-claims`, one client's claims intake, is not part of parity); it is updated with each phase.

- **Covered**: the Workspace does what the package does, to be confirmed against Heratio function by function as each area is finished.
- **Partial**: some of it exists; the phase completes it.
- **Planned**: nothing yet; the phase brings it.

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
| `ahg-ai-services` | 30242 | out: AI (V2+) | Partial | 17 (last) | `ahgAIPlugin`, `ahgAiConditionPlugin` | Proposals and their review queue (v0.0.47, v0.0.48); all AI work deferred to the end |
| `ahg-annotations` | 1286 | V0.1 candidate | Planned | 14 | `ahgAnnotationsPlugin` | As IIIF annotations |
| `ahg-api` | 30902 | V0.1 candidate | Partial | 22 | `ahgAPIPlugin`, `arRestApiPlugin` | Read API, keys, proposals, files (ADR 0007); GraphQL in phase 22 |
| `ahg-api-plugin` | 179 | V0.1 candidate | Covered |  | none | The proposals contract the MCP uses (v0.0.47) |
| `ahg-archaeology` | 4291 | out: GLAM sector | Planned | 21 | `ahgArchaeologyPlugin` |  |
| `ahg-archivematica` | 4049 | later: V1.0+ | Planned | 15 | `arStorageServicePlugin` |  |
| `ahg-articles` | 2447 | later: V1.0+ | Planned | 20 | none |  |
| `ahg-artwork-request` | 1844 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-audit-trail` | 4072 | V0.1 candidate | Covered |  | `ahgAuditTrailPlugin` | Tamper-evident audit log (ADR 0004) |
| `ahg-authority-resolution` | 12466 | V0.1 candidate | Partial | 17 (last) | `ahgAuthorityResolutionPlugin` | A proposal may name the existing record; candidate ranking in phase 17 |
| `ahg-backup` | 5683 | later: V1.0+ | Partial | 3 (last) | `ahgBackupPlugin` | Database backup before each deploy; deferred to the end |
| `ahg-biblio-bf` | 3060 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-biblio-frbr` | 2006 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-c2pa` | 17272 | V0.1 candidate | Planned | 22 | `ahgC2paPlugin` |  |
| `ahg-cart` | 2166 | later: V1.0+ | Planned | 19 | `ahgCartPlugin` |  |
| `ahg-cdpa` | 2774 | later: jurisdiction | Planned | 18 | `ahgCDPAPlugin` |  |
| `ahg-condition` | 1690 | later: V1.0+ | Planned | 21 | `ahgConditionPlugin` |  |
| `ahg-core` | 60173 | V0.1 candidate | Covered |  | `ahgAccessibilityPlugin`, `ahgCorePlugin` | Framework, not a function: the Workspace has its own (Laravel, PostgreSQL); its alternative text for images, with the worklist, is the instantiation's alternative-text element (v0.0.64) |
| `ahg-custom-fields` | 1338 | V0.1 candidate | Planned | 22 | `ahgCustomFieldsPlugin` | As description elements in local vocabularies |
| `ahg-dacs-manage` | 1084 | V0.1 candidate | Covered |  | `ahgDacsManagePlugin`, `arDacsPlugin` | DACS display standard, from v0.0.76 |
| `ahg-dam` | 4736 | out: GLAM sector | Planned | 21 | `ahgDAMPlugin` |  |
| `ahg-data-migration` | 5270 | later: V1.0+ | Partial | 4 | `ahgDataMigrationPlugin` | AtoM CSV and EAD import with dry runs and reports |
| `ahg-dc-manage` | 639 | V0.1 candidate | Covered |  | `ahgDcManagePlugin`, `sfDcPlugin` | Dublin Core export, and the Dublin Core display standard from v0.0.76 |
| `ahg-dedupe` | 3144 | later: V1.0+ | Partial | 22 | `ahgDedupePlugin` | Duplicate authority records; other kinds later |
| `ahg-discovery` | 5836 | V0.1 candidate | Covered |  | `ahgDiscoveryPlugin` | Public catalogue, relationship graph, OAI-PMH |
| `ahg-display` | 10111 | V0.1 candidate | Partial | 20 | `ahgDisplayPlugin` | Public catalogue display; display profiles later |
| `ahg-doi` | 0 | later: V1.0+ | Planned | 20 | none |  |
| `ahg-doi-manage` | 4884 | later: V1.0+ | Planned | 20 | `ahgDoiPlugin` |  |
| `ahg-donor-manage` | 3241 | later: V1.0+ | Partial | 9 | `ahgDonorAgreementPlugin`, `ahgDonorManagePlugin` | Donors as authority records with contact points, and the donor list, from v0.0.77 (9a); donor agreements with their rights, restrictions, reminders and documents from v0.0.78 (9b) |
| `ahg-dropdown-manage` | 1744 | V0.1 candidate | Covered |  | none | Every list is a SKOS vocabulary, editable |
| `ahg-exhibition` | 26806 | out: GLAM sector | Planned | 21 | `ahgExhibitionPlugin` |  |
| `ahg-export` | 1256 | V0.1 candidate | Covered |  | `ahgExportPlugin` | EAD, Dublin Core, MODS, CSV and RiC-O export |
| `ahg-extended-rights` | 7869 | V0.1 candidate | Partial | 13 | `ahgExtendedRightsPlugin` | Rights statements, licences, grants, batch rights and the rights report (v0.0.57 to v0.0.59); embargoes of records and their files, with exceptions, notices and the list from v0.0.89 (13a, ADR 0015); retention and disposal in 13b |
| `ahg-favorites` | 2634 | later: V1.0+ | Planned | 19 | `ahgFavoritesPlugin` |  |
| `ahg-federation` | 15815 | later: V1.0+ | Planned | 16 | `ahgFederationPlugin` |  |
| `ahg-feedback` | 879 | later: V1.0+ | Planned | 19 | `ahgFeedbackPlugin` |  |
| `ahg-forms` | 2541 | V0.1 candidate | Planned | 22 | `ahgFormsPlugin` |  |
| `ahg-ftp-upload` | 2301 | later: V1.0+ | Planned | 22 | `ahgFtpPlugin` |  |
| `ahg-function-manage` | 2606 | V0.1 candidate | Covered |  | `ahgFunctionManagePlugin` | Activities with ISDF elements |
| `ahg-functions-docs` | 716 | later: V1.0+ | Planned | 20 | `ahgFunctionsDocsPlugin` |  |
| `ahg-gallery` | 7210 | out: GLAM sector | Planned | 21 | `ahgGalleryPlugin` |  |
| `ahg-gis` | 280 | V0.1 candidate | Partial | 22 | `ahgGISPlugin` | Places have coordinates and containment; maps later |
| `ahg-graphql` | 764 | later: V1.0+ | Planned | 22 | `ahgGraphQLPlugin` |  |
| `ahg-harris-matrix` | 1343 | out: GLAM sector | Planned | 21 | none |  |
| `ahg-help` | 5771 | later: V1.0+ | Planned | 20 | `ahgHelpPlugin` |  |
| `ahg-heritage-manage` | 12679 | V0.1 candidate | Planned | 21 | `ahgHeritagePlugin`, `ahgHeritageAccountingPlugin` | Heritage asset register and heritage accounting, with the museum domain |
| `ahg-icip` | 6793 | later: jurisdiction | Partial | 18 | `ahgICIPPlugin` | Traditional Knowledge and Biocultural labels (v0.0.59); the rest of ICIP in phase 18 |
| `ahg-iiif-collection` | 8745 | out: GLAM sector | Partial | 14 | `ahgIiifPlugin` | Presentation 3 manifests (PSIS's manifest, Heratio's audio and video canvases), fonds collections, curated collections, Change Discovery, Content State, the Mirador viewer and the IIIF-ready gate from v0.0.87 (14a, ADR 0014); Content Search 2.0, Auth Flow 2.0, saved viewer layouts and validation from v0.0.88 (14b); clearance levels on the probe in phase 18; NER annotations in phase 17, 3D reports in phase 21 |
| `ahg-image-ar` | 896 | out: GLAM sector | Planned | 21 | `ahgImageArPlugin` |  |
| `ahg-inference-receipts` | 1569 | out: AI (V2+) | Partial | 17 (last) | none | Its signing code is copied for the audit chain |
| `ahg-information-object-manage` | 47506 | V0.1 candidate | Covered | 9 | `ahgInformationObjectManagePlugin`, `ahgProvenancePlugin` | Records and record sets: ISAD(G), hierarchy, levels, instantiations, publication; provenance and custody history from v0.0.81 (9e) |
| `ahg-ingest` | 5338 | later: V1.0+ | Planned | 22 | `ahgIngestPlugin` | Read PSIS ahgIngestPlugin too, which does more |
| `ahg-integrity` | 3078 | later: V1.0+ | Partial | 12 | `ahgIntegrityPlugin` | Fixity checked on demand |
| `ahg-ipsas` | 2129 | later: jurisdiction | Planned | 18 | `ahgIPSASPlugin` |  |
| `ahg-jobs` | 950 | later: V1.0+ | Covered | 5 | none | Background jobs and their screen (v0.0.63) |
| `ahg-jobs-manage` | 691 | later: V1.0+ | Planned | 12 | `ahgJobsManagePlugin` |  |
| `ahg-label` | 1383 | later: V1.0+ | Planned | 22 | `ahgLabelPlugin` |  |
| `ahg-landing-page` | 3557 | later: V1.0+ | Planned | 20 | `ahgLandingPagePlugin` |  |
| `ahg-library` | 45610 | out: GLAM sector | Planned | 21 | `ahgLibraryPlugin` |  |
| `ahg-loan` | 3798 | out: GLAM sector | Planned | 21 | `ahgLoanPlugin` |  |
| `ahg-marketing` | 726 | later: V1.0+ | Planned | 20 | none |  |
| `ahg-marketplace` | 19926 | out: GLAM sector | Planned | 22 | `ahgMarketplacePlugin` |  |
| `ahg-media-processing` | 2733 | V0.1 candidate | Partial | 5 | none | Reference copies and thumbnails in a job, made again on request, the institution's watermark on reference copies (v0.0.64); a watermark per object and by security classification in phase 18, audio and video previews and transcription later |
| `ahg-media-streaming` | 1727 | V0.1 candidate | Planned | 22 | none |  |
| `ahg-menu-manage` | 1790 | V0.1 candidate | Covered |  | `ahgMenuManagePlugin` | Navigation written fresh |
| `ahg-metadata-export` | 15322 | V0.1 candidate | Partial | 11 | `ahgMetadataExportPlugin` | EAD 2002, EAD3, DC, MODS, CSV, RiC-O; EAC-CPF import and EAC-F export and import from v0.0.85 (11a); RiC-O import in 11b |
| `ahg-metadata-extraction` | 2770 | V0.1 candidate | Partial | 5 | `ahgMetadataExtractionPlugin` | Embedded metadata read with exiftool and kept on the master; creator, date, title, description and copyright proposed for review (v0.0.64); keywords wait for a subject proposal kind |
| `ahg-mods-manage` | 956 | out: GLAM sector | Covered |  | `ahgModsManagePlugin`, `sfModsPlugin` | MODS export, and the MODS display standard from v0.0.76 |
| `ahg-multi-tenant` | 2626 | later: V1.0+ | Covered |  | `ahgMultiTenantPlugin` | An institution per host, isolated by design; compare with PSIS ahgMultiTenantPlugin, which has more screens |
| `ahg-museum` | 7661 | out: GLAM sector | Planned | 21 | `ahgMuseumPlugin` |  |
| `ahg-narssa` | 581 | later: jurisdiction | Planned | 18 | `ahgNARSSAPlugin` |  |
| `ahg-naz` | 3600 | later: jurisdiction | Planned | 18 | `ahgNAZPlugin` |  |
| `ahg-nmmz` | 2666 | later: jurisdiction | Planned | 18 | `ahgNMMZPlugin` |  |
| `ahg-oai` | 1847 | V0.1 candidate | Covered |  | `arOaiPlugin` | OAI-PMH, Export-Only profile |
| `ahg-observability` | 1903 | later: V1.0+ | Planned | 12 | `ahgObservabilityPlugin` |  |
| `ahg-ocfl` | 3106 | later: V1.0+ | Planned | 15 | `ahgOcflPlugin` |  |
| `ahg-pdf-tools` | 2093 | V0.1 candidate | Partial | 5 | `ahgTiffPdfMergePlugin` | A PDF's text layer proposed as its transcript (v0.0.64); merging TIFFs into a PDF with the scanning workflows, phase 22 |
| `ahg-portable-export` | 3000 | later: V1.0+ | Planned | 11c, deferred | `ahgPortableExportPlugin` | Read PSIS ahgPortableExportPlugin too, which does more; deferred by Dr Johan Pieterse on 30 October 2026 |
| `ahg-preservation` | 10211 | later: V1.0+ | Planned | 15 | `ahgPreservationPlugin` |  |
| `ahg-privacy` | 16854 | later: jurisdiction | Planned | 18 | `ahgPrivacyPlugin` |  |
| `ahg-provenance-ai` | 2662 | out: AI (V2+) | Planned | 17 (last) | none |  |
| `ahg-rad-manage` | 1233 | V0.1 candidate | Covered |  | `ahgRadManagePlugin`, `sfRadPlugin` | RAD display standard, from v0.0.76 |
| `ahg-rdm` | 2739 | later: V1.0+ | Planned | 22 | `ahgRdmPlugin` |  |
| `ahg-records-manage` | 10687 | later: V1.0+ | Planned | 13b, later | `ahgRecordsManagePlugin` | Retention schedules, disposal classes, reviews, the disposal workflow and destruction certificates in 13b; file plans, email capture, classification rules, declaration, vital records and compliance in a later phase (Dr Johan Pieterse, 31 October 2026) |
| `ahg-reports` | 13861 | later: V1.0+ | Done | 8 | `ahgReportBuilderPlugin`, `ahgReportsPlugin` | Reports (overview, data quality, and six lists with filters and CSV), the authority completeness dashboard and the rights report; the report builder (saved reports, columns from the standards, sections, templates, export as CSV, XLSX, PDF and Word); the accession and donor reports come with phase 9; schedules with email, an archive of the files made, expiring share links and versions; not its comments, attachments, links, widgets, data snapshots or cover page |
| `ahg-repository-manage` | 4578 | V0.1 candidate | Covered |  | `ahgRepositoryManagePlugin` | Repositories with ISDIAH elements and contact points |
| `ahg-request-publish` | 1876 | V0.1 candidate | Partial | 10 | `ahgRequestToPublishPlugin` | Review and publish of each value; requests in phase 10 |
| `ahg-research` | 79899 | V0.1 candidate | Planned | 19 | `ahgResearchPlugin` | Decision first |
| `ahg-researcher-manage` | 3111 | V0.1 candidate | Partial | 19 | `ahgResearcherPlugin` | Registration requests. The package itself is researcher submissions (descriptions and files drafted by researchers for staff review), not a register of researchers, so it moved to phase 19 |
| `ahg-resourcesync` | 926 | later: V1.0+ | Planned | 16 | `ahgResourceSyncPlugin` |  |
| `ahg-ric` | 28399 | V0.1 candidate | Partial | 11, 17 | `ahgRicExplorerPlugin` | RiC-native model, RiC-O export, assertions with provenance (ADR 0006); the Governance deprecation register and Portability (RiC-O import, round trip, DCAT/VoID, change feed) from v0.0.86 (11b, ADR 0013); not yet Inferred-Provenance export or Graph-Grounding (`RicProvenanceService`, `GraphGroundingService`, phase 17) |
| `ahg-ric-manage` | 1464 | V0.1 candidate | Covered |  | `ahgRicManagePlugin` | Relations, their types, roles and assertions |
| `ahg-rights` | 0 | V0.1 candidate | Covered |  | `ahgRightsPlugin` | Rights as rico:Rule on records and files (v0.0.57 to v0.0.59, ADR 0008) |
| `ahg-rights-holder-manage` | 5215 | V0.1 candidate | Covered |  | `ahgRightsHolderManagePlugin` | Rights holders are agents, linked by rico:hasOrHadIntellectualPropertyRightsHolder (v0.0.57) |
| `ahg-scan` | 7065 | later: V1.0+ | Planned | 22 | `ahgScanPlugin` |  |
| `ahg-search` | 7659 | V0.1 candidate | Partial | 17 | `ahgSearchPlugin` | Full-text search with facets, staff search and statistics (v0.0.60, v0.0.61, ADR 0009); search by meaning in phase 17 |
| `ahg-security-clearance` | 9206 | later: jurisdiction | Planned | 18 | `ahgSecurityClearancePlugin` |  |
| `ahg-semantic-search` | 29749 | out: AI (V2+) | Planned | 17 (last) | `ahgSemanticSearchPlugin` |  |
| `ahg-settings` | 16789 | V0.1 candidate | Covered |  | `ahgSettingsPlugin` | Institution settings, written fresh |
| `ahg-share-link` | 2659 | later: V1.0+ | Planned | 19 | `ahgTimeLimitedShareLinkPlugin` |  |
| `ahg-sharepoint` | 6045 | later: V1.0+ | Planned | 22 | `ahgSharePointPlugin` |  |
| `ahg-spectrum` | 12393 | out: GLAM sector | Planned | 21 | `ahgSpectrumPlugin` |  |
| `ahg-static-page` | 695 | later: V1.0+ | Planned | 20 | `ahgStaticPagePlugin` |  |
| `ahg-statistics` | 1906 | later: V1.0+ | Done | 8 | `ahgStatisticsPlugin` | Views and downloads of the public catalogue by day, item and repository, web crawlers apart, CSV; no address, user agent or place is kept, so there is no count by country |
| `ahg-storage-manage` | 7645 | V0.1 candidate | Covered |  | `ahgStorageManagePlugin` | Containers and storage places, with movements |
| `ahg-term-taxonomy` | 6138 | V0.1 candidate | Covered |  | `ahgTermTaxonomyPlugin` | SKOS vocabularies: bundled, local, imported, matched |
| `ahg-theme-b5` | 4574 | V0.1 candidate | Covered |  | `ahgThemeB5Plugin`, `ahgUiOverridesPlugin` | Interface written fresh |
| `ahg-translation` | 5726 | out: AI (V2+) | Planned | 17 (last) | `ahgTranslationPlugin` |  |
| `ahg-user-manage` | 5651 | V0.1 candidate | Covered | 12 | `ahgUserManagePlugin`, `ahgUserRegistrationPlugin`, `arOidcPlugin` | Accounts, registration, roles; written fresh; OIDC single sign-on in phase 12 |
| `ahg-vendor` | 6980 | later: V1.0+ | Planned | 22 | `ahgVendorPlugin` |  |
| `ahg-version-control` | 3142 | later: V1.0+ | Partial | 22 | `ahgVersionControlPlugin` | Every change is in the audit log; version views later |
| `ahg-workflow` | 7830 | later: V1.0+ | Covered | 10 | `ahgWorkflowPlugin` | Workflows, steps, tasks, pool, assignment, decisions, team work, history and bulk operations from v0.0.82 (10a); queues, service levels, overdue notices, publish gates, readiness and the diagram from v0.0.84 (10b); Spectrum procedures in phase 21 |
| `ahg-z3950` | 5243 | out: GLAM sector | Planned | 21 | none |  |

: Heratio packages and their Workspace status

Totals: 23 covered, 25 partial, 72 planned, of 120 (27 October 2026).

## PSIS plugins without a Heratio package

Checked on 27 October 2026 against PSIS (`archive/plugins`, 143 plugins: 124 of AHG's and 19 of AtoM's own). Every other PSIS plugin is named beside its Heratio twin in the table above.

| PSIS plugin | What it does | Where it goes |
|---|---|---|
| `ahgEmailDeliveryPlugin` | Bounce capture, suppression list, a gate before each send | Done in v0.0.65 from Heratio's `app/` (`EmailBounceController`, `EmailSuppressionGate`, `EmailAuditListener`), with a screen for sent mail and held addresses |
| `ahgSAHRAPlugin` | SAHRA heritage permits under the National Heritage Resources Act | Phase 18 |
| `ahgSiteRecordPlugin` | Archaeological, heritage and rock art site records, locality shown by role | Phase 21 |
| `ahgRegistryPlugin` | The AHG community hub and registry | Out of scope: a separate product |
| `ahgUiOverridesPlugin` | Overrides of AtoM module actions | Not needed: specific to AtoM |
| `qtSwordPlugin` | SWORD deposit | Phase 15, written fresh |
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
| `requested_classification_id` (security clearance) | none | Phase 18 |

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
| `required_clearance_level` | Required clearance level | Modelled: kept, applied with phase 18 |
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
