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
| `ahg-accession-manage` | 6795 | later: V1.0+ | Planned | 9 | `ahgAccessionManagePlugin`, `qtAccessionPlugin` |  |
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
| `ahg-dacs-manage` | 1084 | V0.1 candidate | Planned | 11 | `ahgDacsManagePlugin`, `arDacsPlugin` | Description templates beside ISAD(G) |
| `ahg-dam` | 4736 | out: GLAM sector | Planned | 21 | `ahgDAMPlugin` |  |
| `ahg-data-migration` | 5270 | later: V1.0+ | Partial | 4 | `ahgDataMigrationPlugin` | AtoM CSV and EAD import with dry runs and reports |
| `ahg-dc-manage` | 639 | V0.1 candidate | Partial | 11 | `ahgDcManagePlugin`, `sfDcPlugin` | Dublin Core export; a DC template in phase 11 |
| `ahg-dedupe` | 3144 | later: V1.0+ | Partial | 22 | `ahgDedupePlugin` | Duplicate authority records; other kinds later |
| `ahg-discovery` | 5836 | V0.1 candidate | Covered |  | `ahgDiscoveryPlugin` | Public catalogue, relationship graph, OAI-PMH |
| `ahg-display` | 10111 | V0.1 candidate | Partial | 20 | `ahgDisplayPlugin` | Public catalogue display; display profiles later |
| `ahg-doi` | 0 | later: V1.0+ | Planned | 20 | none |  |
| `ahg-doi-manage` | 4884 | later: V1.0+ | Planned | 20 | `ahgDoiPlugin` |  |
| `ahg-donor-manage` | 3241 | later: V1.0+ | Planned | 9 | `ahgDonorAgreementPlugin`, `ahgDonorManagePlugin` | With donor agreements |
| `ahg-dropdown-manage` | 1744 | V0.1 candidate | Covered |  | none | Every list is a SKOS vocabulary, editable |
| `ahg-exhibition` | 26806 | out: GLAM sector | Planned | 21 | `ahgExhibitionPlugin` |  |
| `ahg-export` | 1256 | V0.1 candidate | Covered |  | `ahgExportPlugin` | EAD, Dublin Core, MODS, CSV and RiC-O export |
| `ahg-extended-rights` | 7869 | V0.1 candidate | Partial | 13 | `ahgExtendedRightsPlugin` | Rights statements, licences, grants, batch rights and the rights report (v0.0.57 to v0.0.59); embargoes of records, retention and disposal in phase 13 |
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
| `ahg-iiif-collection` | 8745 | out: GLAM sector | Planned | 14 | `ahgIiifPlugin` | Read PSIS ahgIiifPlugin too, which does more |
| `ahg-image-ar` | 896 | out: GLAM sector | Planned | 21 | `ahgImageArPlugin` |  |
| `ahg-inference-receipts` | 1569 | out: AI (V2+) | Partial | 17 (last) | none | Its signing code is copied for the audit chain |
| `ahg-information-object-manage` | 47506 | V0.1 candidate | Covered | 9 | `ahgInformationObjectManagePlugin`, `ahgProvenancePlugin` | Records and record sets: ISAD(G), hierarchy, levels, instantiations, publication; provenance and custody history in phase 9 |
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
| `ahg-metadata-export` | 15322 | V0.1 candidate | Partial | 11 | `ahgMetadataExportPlugin` | EAD, DC, MODS, CSV, RiC-O; more crosswalks in phase 11 |
| `ahg-metadata-extraction` | 2770 | V0.1 candidate | Partial | 5 | `ahgMetadataExtractionPlugin` | Embedded metadata read with exiftool and kept on the master; creator, date, title, description and copyright proposed for review (v0.0.64); keywords wait for a subject proposal kind |
| `ahg-mods-manage` | 956 | out: GLAM sector | Partial | 21 | `ahgModsManagePlugin`, `sfModsPlugin` | MODS export |
| `ahg-multi-tenant` | 2626 | later: V1.0+ | Covered |  | `ahgMultiTenantPlugin` | An institution per host, isolated by design; compare with PSIS ahgMultiTenantPlugin, which has more screens |
| `ahg-museum` | 7661 | out: GLAM sector | Planned | 21 | `ahgMuseumPlugin` |  |
| `ahg-narssa` | 581 | later: jurisdiction | Planned | 18 | `ahgNARSSAPlugin` |  |
| `ahg-naz` | 3600 | later: jurisdiction | Planned | 18 | `ahgNAZPlugin` |  |
| `ahg-nmmz` | 2666 | later: jurisdiction | Planned | 18 | `ahgNMMZPlugin` |  |
| `ahg-oai` | 1847 | V0.1 candidate | Covered |  | `arOaiPlugin` | OAI-PMH, Export-Only profile |
| `ahg-observability` | 1903 | later: V1.0+ | Planned | 12 | `ahgObservabilityPlugin` |  |
| `ahg-ocfl` | 3106 | later: V1.0+ | Planned | 15 | `ahgOcflPlugin` |  |
| `ahg-pdf-tools` | 2093 | V0.1 candidate | Partial | 5 | `ahgTiffPdfMergePlugin` | A PDF's text layer proposed as its transcript (v0.0.64); merging TIFFs into a PDF with the scanning workflows, phase 22 |
| `ahg-portable-export` | 3000 | later: V1.0+ | Planned | 11 | `ahgPortableExportPlugin` | Read PSIS ahgPortableExportPlugin too, which does more |
| `ahg-preservation` | 10211 | later: V1.0+ | Planned | 15 | `ahgPreservationPlugin` |  |
| `ahg-privacy` | 16854 | later: jurisdiction | Planned | 18 | `ahgPrivacyPlugin` |  |
| `ahg-provenance-ai` | 2662 | out: AI (V2+) | Planned | 17 (last) | none |  |
| `ahg-rad-manage` | 1233 | V0.1 candidate | Planned | 11 | `ahgRadManagePlugin`, `sfRadPlugin` |  |
| `ahg-rdm` | 2739 | later: V1.0+ | Planned | 22 | `ahgRdmPlugin` |  |
| `ahg-records-manage` | 10687 | later: V1.0+ | Planned | 13 | `ahgRecordsManagePlugin` |  |
| `ahg-reports` | 13861 | later: V1.0+ | Partial | 8 | `ahgReportBuilderPlugin`, `ahgReportsPlugin` | Reports (overview, data quality, and six lists with filters and CSV), the authority completeness dashboard and the rights report; the report builder (saved reports, columns from the standards, sections, templates, export as CSV, XLSX, PDF and Word); the accession and donor reports come with phase 9; schedules, share links and versions of built reports next |
| `ahg-repository-manage` | 4578 | V0.1 candidate | Covered |  | `ahgRepositoryManagePlugin` | Repositories with ISDIAH elements and contact points |
| `ahg-request-publish` | 1876 | V0.1 candidate | Partial | 10 | `ahgRequestToPublishPlugin` | Review and publish of each value; requests in phase 10 |
| `ahg-research` | 79899 | V0.1 candidate | Planned | 19 | `ahgResearchPlugin` | Decision first |
| `ahg-researcher-manage` | 3111 | V0.1 candidate | Partial | 19 | `ahgResearcherPlugin` | Registration requests. The package itself is researcher submissions (descriptions and files drafted by researchers for staff review), not a register of researchers, so it moved to phase 19 |
| `ahg-resourcesync` | 926 | later: V1.0+ | Planned | 16 | `ahgResourceSyncPlugin` |  |
| `ahg-ric` | 28399 | V0.1 candidate | Partial | 11, 17 | `ahgRicExplorerPlugin` | RiC-native model, RiC-O export, assertions with provenance (ADR 0006); not yet the Governance deprecation register, Portability (RiC-O import, round-trip, DCAT/VoID), Inferred-Provenance export or Graph-Grounding (`RicDeprecationService`, `RdfImportService`, `RicDatasetService`, `RicProvenanceService`, `GraphGroundingService`) |
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
| `ahg-workflow` | 7830 | later: V1.0+ | Planned | 10 | `ahgWorkflowPlugin` |  |
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
