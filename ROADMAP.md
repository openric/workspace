# Roadmap

From the OpenRiC Workspace specification and delivery plan of 18 October 2026 (direction accepted; owners, standard acceptance, source reuse clearance, database choice and estimates open). No calendar dates are given until staffing, the S-GATE and a pilot institution are confirmed.

## Releases

| Release | Purpose | Not claimed |
|---|---|---|
| V0.1, proof of concept | Archivists and reviewers validate one end-to-end workflow on representative test data, on an independently deployed instance. Built to production standard (see the hard constraints in `SPECIFICATION.md`): the proof is narrow, the code is not throwaway. | Production service, complete archival lifecycle, certification. |
| V0.5, pilot | Controlled testing with real archivists and agreed non-critical data: import, search, roles, accessibility, restore. | Unattended institution-wide use. |
| V1.0, operational | A small archive does ordinary work safely: accession, arrangement, description, authorities, digital objects, controlled publication, import and export, backup and restore, monitoring. | A comprehensive GLAM platform or an OAIS-certified repository. |
| V2.0 and later | Broader institutions and integrations. | No fixed scope or date until discovery and funding. |

## Delivery gates

Nobody marks a gate passed without named signatories and stored evidence.

| Gate | Pass evidence | Status |
|---|---|---|
| G0, programme authorisation | Named leads; the Workspace name; release definitions; scope exclusions; pilot candidate; priorities. | Open: name decided ("OpenRiC Workspace"), release definitions above; leads and pilot candidate not named |
| S, Standard acceptance | The OpenRiC S-GATE: versioned specification, profile register, independent fixtures, documented deviations, formal acceptance. | Open: baseline set at OpenRiC v0.48.0 (19 October 2026); external review and sign-off pending |
| G1, legal and architecture baseline | Pinned Heratio commit, inventory and rights ledger, prohibited-copy list, architecture decision records, database choice and spike, logical model. | Not started |
| G2, end-to-end proof (V0.1) | Independent installation, scripted demonstration, relations with provenance, multilingual values, safe files, review and publish, browse and search, validated export, backup and restore. | Not started |
| G3, migration feasibility | A difficult Heratio sample reconciled; identifiers, relations, access rules and translations assessed; unsupported mappings explicit. | Not started |
| G4, controlled pilot (V0.5) | Usability, representative data, accessibility, logging, disaster-recovery rehearsal, access-control negative tests. | Not started |
| G5, production release (V1.0) | Accepted workflow, security and accessibility review, full restore, supported import, conformance report, SBOM and licences, runbooks. | Not started |
| G6, expansion (V2.0+) | Demand evidence, domain discovery, compatibility and migration story, module boundaries. | Not started |

## Implementation and the S-GATE

Implementation has started before the S-GATE, by decision of Dr Johan Pieterse on 19 October 2026 ([ADR 0003](architecture/adr/0003-implementation-before-the-s-gate.md)). The S-GATE remains the gate for the standard. The Workspace builds against a pinned, released specification version; takes any gap it finds to openric/spec as an issue before using it; passes the probe and term check on every build; and never counts as the independent implementation the OpenRiC v1.0 gate needs.

## Work moved from the OpenRiC reference service

On 19 October 2026 the open feature issues of `openric/service` were moved here, because editing and assertion management are Workspace work and the reference service is not to grow into a second application. The specification-side part of each stays with `openric/spec`.

| Service issue | Workspace requirement | Release | What stays in the specification |
|---|---|---|---|
| #11 role an agent plays in a relation (picker and editing) | WS-003, WS-006 | V0.1 | The [role-type vocabulary](https://openric.org/vocab/role-type.html) and `openricx:relationHasAgentRole` |
| #17 competing assertions and supersession | WS-006 | V0.1 | The Inferred-Provenance profile and its assertion-status terms |
| #14 EAC-F to RiC-O crosswalk, import and export | WS-015 | V1.0 | The crosswalk as a mapping document |
| #15 RiC and IIIF integration pattern | WS-019 | V2.0 | The pattern as specification guidance |
| #16 mapping to Wikidata, Linked Art and CIDOC CRM | WS-019 | V2.0 | The mappings as research notes |

: Service issues moved to the Workspace

Issues #8, #12 and #13 stay with the service and the specification: they wait on ICA-EGAD (RiC-O 1.2, issue #146, PROF profile PR #159).

The V0.1 demonstration scenario should carry #11 and #17 together: a participant whose role is recorded with its evidence, and later corrected on new evidence, with both assertions kept.

## Work brought forward

On 26 October 2026 Dr Johan Pieterse brought part of WS-018 (integration protocols) and WS-019 (AI assistance with human review) forward from V2.0, so that the AHG Archives MCP can serve the Workspace in full ([ADR 0007](architecture/adr/0007-api-keys-and-proposals.md)). It is built in four steps, each finished before the next:

| Step | What | Release |
|---|---|---|
| 1 | API keys: scopes no wider than the account's, end date, hourly and daily limits, last use, revocation, key pages | v0.0.46 |
| 2 | Proposals through the API, and a caller's list of them | v0.0.47 |
| 3 | The review queue: approve, edit or reject each proposal | v0.0.48 |
| 4 | File text and images through the API | v0.0.49 |

: API keys and proposals, brought forward

## Next phases, with Heratio code reused

Planned on 26 October 2026, after ADR 0007 was completed in v0.0.49. The end product, as Dr Johan Pieterse set it on 26 October 2026, has the functionality of Heratio, built on the Workspace's RiC-native model rather than the AtoM schema: every Heratio package (120 of them; `ahg-mva-claims`, one client's claims intake, is not part of it) ends up done, copied or deliberately replaced, and `governance/HERATIO-PARITY.md` records where each one stands. V1.0 is still not claimed as a complete GLAM platform; parity is reached across V2.0 and later. Each phase closes a gap between what the Workspace does and what a requirement asks, copies what Heratio already does, and ships as one or more tested releases. The order follows the release definitions above: finish the V0.1 proof first, then what a pilot needs, then V1.0, then V2.0 work that has a decision behind it.

How Heratio code is reused. The rule is to reuse as much of Heratio's code as possible: copy the file, and change only what must change. Dr Johan Pieterse set this on 26 October 2026. It replaces the G1 audit's lean towards porting the behaviour, which rested on a count that overstated the coupling to AtoM (see "Measured again" in `governance/G1-HERATIO-SOURCE-AUDIT.md`). Measured on heratio-dev:
- Heratio has 4,787 PHP files and 913,501 lines.
- 988 files (21%) touch AtoM's tables at all.
- 12,989 lines (1.4%) name one.
- No file carries Artefactual's or AtoM's copyright.

So:

- **Copy** (the default): the files that do not touch the AtoM schema, about four in five, keep their code. Changes are mechanical: namespace, the Workspace's models and services in place of Heratio's, UUIDs and IRIs for integer ids and slugs, BCP 47 tags for cultures, vocabulary concepts for term ids, and the Workspace's layout for the theme.
- **Copy and replace the queries**: in the files that do touch the AtoM schema, the queries are rewritten against the Workspace's model and the rest of the file is kept (prohibited-copy item 3 forbids only the AtoM data access, not the file).
- **Not reused**, and each case is said in the phase that meets it:
  - a function the Workspace already has;
  - code that would call Heratio at run time (prohibited-copy item 4);
  - the 12 templates on the prohibited-copy list;
  - a hard-coded list that must become a vocabulary;
  - a file whose origin cannot be established.

Every copied file keeps its header, gains its source commit and has a row in `governance/CODE_PROVENANCE.md` before it is committed, from a pinned heratio-dev commit. Heratio's shared helpers in `ahg-core`, which 475 files of other packages use, are copied with the first phase that needs them, or mapped to the Workspace's own.

Standing rules for every phase: vocabularies, never enums; every field names its RiC-O or `openricx:` term, and a missing term goes to openric/spec as an issue first; anything an AI model produces arrives as a proposal for the review queue, never as a change; AI calls go through the AHG AI gateway; access rules are tested with negative tests; each phase ends with its tests, its ledger rows, an ADR where it decides something, and a KM entry.

| Phase | What | Requirement | Release | Heratio source (copied, unless said) |
|---|---|---|---|---|
| 1 | Interface in more than one language | WS-005 | V0.1 | `lang/*.json` (copy after a provenance check: the strings may come from AtoM's translations, whose notice must travel with them) |
| 2 | Rights statements and rights holders on files | WS-007 | V0.1 | `ahg-extended-rights`, `ahg-rights-holder-manage` |
| 3 | Backup, restore drill and restore verification | WS-011 | V0.1 | `ahg-backup` |
| 4 | Heratio migration check on a difficult sample | WS-013 | V0.1 | `ahg-data-migration`, with the existing AtoM import |
| 5 | Background jobs, file text and embedded metadata | WS-007, WS-016 | V0.5 | `ahg-pdf-tools`, `ahg-media-processing`; `ahg-metadata-extraction`, `ahg-jobs` |
| 6 | Full-text search with facets | WS-016 | V0.5 | `ahg-search` (its queries on PostgreSQL full text instead of Elasticsearch, by ADR) |
| 7 | Access requests for files on request | WS-007, WS-011 | V0.5 | `ahg-access-request`, `ahg-researcher-manage` |
| 8 | Reports and statistics | WS-016 | V0.5 | `ahg-reports`, `ahg-statistics` |
| 9 | Accessions and donors | WS-014 | V1.0 | `ahg-accession-manage` (CAIS profile), `ahg-donor-manage` |
| 10 | Work assignment and description workflow | WS-014 | V1.0 | `ahg-workflow` (not its Spectrum procedures) |
| 11 | Exchange formats: EAD 3, EAC-CPF, EAC-F, RiC-O import; portable export; the Governance and Portability profiles | WS-010, WS-015 | V1.0 | `ahg-metadata-export`, `ahg-portable-export`; from `ahg-ric`: `RicDeprecationService`, `RdfImportService`, `RicDatasetService`, `RicRoundTripTest` |
| 12 | Monitoring, scheduled fixity, update and restore runbooks | WS-017 | V1.0 | `ahg-observability`, `ahg-integrity`, `ahg-jobs-manage` |
| 13 | Embargoes, retention and disposal | WS-017, WS-018 | V1.0 | `ahg-extended-rights` (embargo, retention schedule, disposal), `ahg-records-manage` (after discovery) |
| 14 | IIIF manifests and viewer | WS-019 | V2.0 | `ahg-iiif-collection`, with openric/service #15 |
| 15 | Preservation connectors | WS-019 | V2.0 | `ahg-preservation` (PREMIS events), `ahg-ocfl`, `ahg-archivematica` |
| 16 | Federation and harvesting | WS-019 | V2.0 | `ahg-federation`, `ahg-resourcesync`, beside the existing OAI-PMH |
| 17 | AI assistance through the review queue; the Inferred-Provenance and Graph-Grounding profiles | WS-019 | V2.0 | `ahg-authority-resolution`, `ahg-translation`, `ahg-semantic-search` (output only as proposals); from `ahg-ric`: `RicProvenanceService`, `GraphGroundingService` |
| 18 | Privacy and jurisdiction packs | V2.0 and later | V2.0 | `ahg-privacy` (POPIA), `ahg-security-clearance`, `ahg-narssa` (per institution) |
| 19 | Research services | WS-016, WS-019 | V2.0 | `ahg-research` (decide what to take first; 79,899 lines), `ahg-cart`, `ahg-favorites`, `ahg-feedback`, `ahg-share-link` |
| 20 | Publishing and outreach | WS-019 | V2.0 | `ahg-landing-page`, `ahg-static-page`, `ahg-articles`, `ahg-help`, `ahg-doi-manage` |
| 21 | Other GLAM domains on the RiC model | V2.0 and later | V2.0+ | `ahg-library`, `ahg-museum`, `ahg-gallery`, `ahg-dam`, `ahg-exhibition`, `ahg-loan`, `ahg-spectrum`, `ahg-3d-model`, `ahg-archaeology` and their companions (one domain at a time) |
| 22 | Remaining Heratio services | V2.0 and later | V2.0+ | ingest, scanning, labels, condition, vendors, version history, C2PA and inference receipts, GraphQL, SharePoint and FTP intake, AI chat and compliance (copied or replaced, each by the parity register) |

: Next phases and their Heratio sources

### Phase details

**1. Interface in more than one language (WS-005).** The interface was English only; WS-005 asks for interface localisation beside the multilingual values the Workspace already keeps. Every screen's text moves to Laravel language files, with a language switch for each account and for the public catalogue. English and Afrikaans first. Heratio's `lang/*.json` holds translations for many languages; each file's origin is checked first, because strings taken from AtoM's XLIFF files carry Artefactual's copyright and must keep its notice. Done when a reviewer completes the V0.1 scenario in Afrikaans. Progress:
- **v0.0.50**: the language machinery and the public side.
  - Copied from Heratio: the language choice, in order the URL, session, cookie, the account's choice, then the browser; the switch at `/language`; Content-Language on every page.
  - In both languages: the layouts, the menus, the public catalogue, sign-in, registration and the home page.
  - Vocabulary labels and relation phrases now follow the reader's language.
  - `lang/af.json` has 199 strings, 32 of them from Heratio; attribution is in `lang/README.md`.
- **v0.0.51**: PSIS's archival English, in South African spelling, and correct Afrikaans, where PSIS's is correct and the right term where it is not.
- **v0.0.52**: the staff screens' plain text, 946 strings in 106 views, wrapped by a script that masks all Blade and PHP code first. `lang/af.json` now has 780 strings: 62 from PSIS, 27 from Heratio, 691 written for the Workspace.
- **v0.0.53**: the remaining text mixed with values, about 150 sentences with placeholders, including whole sentences for singular and plural and for a dry run and an import; English in PHP expressions; the public relationship graph and the hierarchy tree.
  - The completeness test now also reads keys chosen at run time.
  - `lang/af.json` has 993 strings: 62 from PSIS, 27 from Heratio, 904 written for the Workspace.
- **Still to do**: the messages written in code (status messages and validation errors), Laravel's validation messages, and Afrikaans labels for the bundled vocabularies.

Reuse was lower than hoped: only 32 of Heratio's Afrikaans strings carried over. Heratio's strings are keyed by its own English, which is mostly AtoM's, and the Workspace's screens were written in different English.

**2. Rights statements and rights holders (WS-007).** WS-007 asks for rights on each file; today an instantiation only has free text for its rights. Copy `ahg-extended-rights`: a rights statement per instantiation from RightsStatements.org and the Creative Commons licences, as bundled vocabularies, with its basis (copyright, licence, statute, donor), its holder and its dates. The rights holder is an agent, related by a typed relation, from `ahg-rights-holder-manage`. Rights show in the public catalogue and in the files API, and go out in exports. The RiC term is checked first (`rico:Rule`, `rico:hasOrHadLegalStatus`, or a spec issue). Done when every delivered file carries its statement.

**3. Backup and restore (WS-011).** `deploy/deploy.sh` already backs up the database before a release. Copy `ahg-backup`: scheduled backups of the database and the object store together, an offsite copy (its local, rsync and S3 drivers, which do not touch the AtoM schema), and a restore that checks every file's SHA-256 and the audit chain afterwards. Done when a restore drill on a fresh machine is recorded, as the G2 evidence asks.

**4. Heratio migration check (WS-013, G3).** The AtoM import reads AtoM CSV exports and AtoM's uploads. Copy the mapping rules of `ahg-data-migration` to take a difficult Heratio sample (inherited access rules, translations, relations, embargoed records). A reconciliation report lists, for each source row, what was kept, changed and lost. Done when the report for the agreed sample shows nothing lost silently.

**5. Background jobs, file text and embedded metadata (WS-007, WS-016).** Uploads and imports run in the request today. Add a database-backed queue, copying the job runner and its screen from `ahg-jobs`. Then:
- copy `PdfTextExtractService` from `ahg-pdf-tools` and the derivative and watermark code from `ahg-media-processing`, which do not touch the AtoM schema;
- copy `ahg-metadata-extraction` for embedded metadata such as EXIF and XMP;
- send scanned pages for OCR through the AI gateway.

Text read by a machine is a proposal for the instantiation's transcript, reviewed in the queue built in v0.0.48. Done when an uploaded PDF and a scanned page both produce a reviewable transcript.

**6. Full-text search (WS-016).** Search is a substring match on names and identifiers. Copy the behaviour of `ahg-search` (query syntax, facets by level, date, repository, subject and place, highlighting, analytics), on PostgreSQL full-text search with one configuration per language, decided by ADR against Elasticsearch, which Heratio uses. Index only what the reader may see, so the public catalogue and the API search published values only. Done when the acceptance queries and the access negative tests pass.

**7. Access requests (WS-007, WS-011).** An instantiation "on request" can be opened today only by staff. Copy `ahg-access-request`: a signed-in researcher asks for a file, giving a reason; staff approve or refuse; approval gives that researcher time-limited access; each step is audited and emailed. Registered researchers come from `ahg-researcher-manage`, beside the registration requests that already exist. Done when a researcher's approved request opens the file and an expired one does not.

**8. Reports and statistics (WS-016).** Copy the reports of `ahg-reports` (holdings by repository and level, publication status, completeness, rights, catalogue growth, collections health), with the authority completeness that already exists. Copy the usage counts of `ahg-statistics`, without storing visitors' addresses. Every report exports as CSV. Done when a pilot archivist answers their institution's routine questions without help.

**9. Accessions and donors (WS-014).** Copy `ahg-accession-manage`, which follows the Canadian CAIS profile (`CaaisProfileService`), and `ahg-donor-manage`. An accession register records what arrived, from whom, when and on what terms. The donor is an agent with contact points; the agreement is a record. RiC models an accession as an activity, so this needs an ADR on the mapping, and on keeping donor contact details encrypted as Heratio does. Done when a pilot archivist registers a deposit and links it to the fonds it became.

**10. Work assignment and workflow (WS-014).** Copy the task model of `ahg-workflow`: tasks on records, assigned to people, with due dates, overdue notices and bulk assignment. Its Spectrum procedures are museum work and stay out. The task states form a vocabulary, and publication remains the existing review and publish step. Done when a team divides a fonds' description among its members and sees what is left.

**11. Exchange formats (WS-015).** The Workspace imports AtoM CSV and EAD and exports EAD, Dublin Core, MODS, CSV and RiC-O. To add:
- EAD 3;
- EAC-CPF import (export exists);
- EAC-F, which openric/service #14 asks for;
- RiC-O import, with exception reports and dry runs like the existing import.

The crosswalks of `ahg-metadata-export` are copied. With them, two OpenRiC profiles the Workspace does not yet declare:
- **Governance**: a deleted entity's IRI answers as deprecated (`owl:deprecated`, `dcterms:isReplacedBy` its successor, the reason), never as missing, from a deprecation register; copied from Heratio's `RicDeprecationService`. Today a deleted record simply disappears from the API.
- **Portability**: the RiC-O import above with a round-trip test, a DCAT and VoID dataset descriptor and a change feed; copied from `RdfImportService`, `RicDatasetService` and `RicRoundTripTest`.

These profiles came from ArchiveHeritageGroup/OpenRiC #1 to #3, now in the specification. `ahg-portable-export` (an offline catalogue for a USB stick or a reading room) is copied with light change. Done when each format round-trips the demonstration data, every rejected field is listed, and nothing is lost silently.

**12. Monitoring and operations (WS-017).**
- Copy `ahg-observability` for health and metrics.
- Copy `ahg-integrity` for fixity checks on a schedule, with reports of failures.
- Copy `ahg-jobs-manage` for the operator's view of jobs.
- Write runbooks for updating, restoring and rotating keys.

Done when the security, accessibility and disaster-recovery sign-offs of WS-017 have their evidence.

**13. Embargoes, retention and disposal (WS-017, WS-018).** Copy the embargo service, notices and reports of `ahg-extended-rights`; agents' embargoes already exist, and this extends embargoes to records and files. Its retention schedule and disposal workflow, with `ahg-records-manage`, follow a discovery with a records manager. Disposal is a reviewed, audited activity that leaves a record of what was destroyed. Done when a disposal is approved, carried out and reported, with nothing removed outside it.

**14. IIIF (WS-019).** Copy the manifest building of `ahg-iiif-collection`: a IIIF Presentation 3 manifest for each record from its reference copies, a collection for each fonds, and a viewer in the public catalogue. Follow the RiC and IIIF pattern of openric/service #15 as specification guidance. Done when the manifests validate and the MCP's image tool can follow them.

**15. Preservation connectors (WS-019).**
- Copy PREMIS events from `ahg-preservation`: ingest, fixity, migration.
- Copy the OCFL storage layout from `ahg-ocfl`.
- Copy the Archivematica transfer and status from `ahg-archivematica`.

Each one is optional per institution. Done when an instantiation's preservation history can be exported as PREMIS.

**16. Federation and harvesting (WS-019).** Copy `ahg-federation` (shared search across Workspace institutions and peers, one visitor key per peer, as the MCP does) and `ahg-resourcesync`, beside the OAI-PMH that already exists. Done when two institutions search each other's published catalogue and neither sees the other's drafts.

**17. AI assistance (WS-019).** Copy the candidate ranking of `ahg-authority-resolution`, so the review queue offers the likely authority record for a proposed name. Copy `ahg-translation` for proposed translations and `ahg-semantic-search` for search by meaning. All of it runs through the AI gateway and produces proposals only, as ADR 0007 decided. With it, two more OpenRiC profiles:
- **Inferred-Provenance**: what a model asserted is exported as inferred (`prov:wasGeneratedBy`, confidence), from the assertion origins the Workspace already records; copied from `RicProvenanceService`.
- **Graph-Grounding**: `GET /ground`, disambiguation packs keyed to entity IRIs, for agents such as the MCP; copied from `GraphGroundingService`.

These came from ArchiveHeritageGroup/OpenRiC #4 and #5. Done when every machine output in the Workspace can be traced to its proposal and its reviewer.

**18. Privacy and jurisdiction packs.** Copy `ahg-privacy` (POPIA: personal information in descriptions, requests for access and correction), `ahg-security-clearance` and national packs such as `ahg-narssa`. Each is enabled per institution and decided by ADR. Done when a South African institution can show POPIA compliance for its catalogue.

**19. Research services.** Copy the reading-room side: a researcher's cart and favourites (`ahg-cart`, `ahg-favorites`), feedback on records (`ahg-feedback`), time-limited share links that respect access rules (`ahg-share-link`). `ahg-research` is the largest Heratio package, at 79,899 lines; what of it the Workspace takes is decided by ADR before it is copied. Done when a researcher can find, collect, request and cite material in one visit.

**20. Publishing and outreach.** Copy institution landing pages, static pages and articles (`ahg-landing-page`, `ahg-static-page`, `ahg-articles`), in-application help (`ahg-help`), and DOIs minted for published records (`ahg-doi-manage`, with the DOI pointing at the record's persistent IRI). Done when an institution runs its public site from the Workspace.

**21. Other GLAM domains on the RiC model.** Heratio also serves libraries, museums, galleries and digital asset collections. RiC-O describes records, so each domain starts with a mapping decision (RiC with domain extensions, or a mapping to CIDOC CRM, Linked Art or BIBFRAME, which openric/service #16 researches), recorded by ADR before its copy. Then one domain at a time:
- library: `ahg-library`, `ahg-biblio-bf`, `ahg-biblio-frbr`, `ahg-z3950`, `ahg-mods-manage`;
- museum: `ahg-museum`, `ahg-spectrum`, `ahg-condition`, `ahg-loan`, `ahg-exhibition`, `ahg-artwork-request`;
- gallery and digital assets: `ahg-gallery`, `ahg-dam`, `ahg-3d-model`, `ahg-image-ar`;
- archaeology: `ahg-archaeology`, `ahg-harris-matrix`.

Done for each domain when its Heratio functions work on the RiC model and export in its own standard.

**22. Remaining Heratio services.** Whatever the parity register still lists as open after phase 21 is copied, or replaced by something the Workspace already does. That includes ingest and scanning workflows, box labels, vendors, version history, C2PA and inference receipts (whose signing code is already copied for the audit chain), GraphQL, SharePoint and FTP intake, the AI chatbot and AI compliance. Done when the parity register has no open row.

Not copied: the 12 templates on the prohibited-copy list, until each is compared with AtoM's own; the Workspace's own theme, menus and accounts already do their work. Everything else in Heratio is a candidate to copy.

## Next actions

1. Name the product, archival domain, standards, technical, legal and provenance, and security leads; recruit one pilot institution (G0).
2. Freeze the V0.1 demonstration scenario and sample dataset, including one relationship whose evidence can change.
3. Run the Heratio source audit and the relational, rights and provenance spikes, each recorded as an ADR (G1).
4. Estimate the V0.1 work by role and dependency after the spikes; publish only conditional targets.
