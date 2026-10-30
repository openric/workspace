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
| 4 | Heratio migration check on a difficult sample | WS-013 | V0.1 | `ahg-data-migration`, with the existing AtoM import |
| 5 | Background jobs, file text, embedded metadata, image alt text and watermarks | WS-007, WS-016 | V0.5 | `ahg-pdf-tools`, `ahg-media-processing`; `ahg-metadata-extraction`, `ahg-jobs`; alt text from `ahg-core` (PSIS `ahgAccessibilityPlugin`) |
| 6 | Full-text search with facets | WS-016 | V0.5 | `ahg-search` (its queries on PostgreSQL full text instead of Elasticsearch, by ADR) |
| 7 | Access requests for files on request; email delivery | WS-007, WS-011 | V0.5 | `ahg-access-request`; Heratio `app/` `EmailBounceController`, `EmailSuppressionGate` (PSIS `ahgEmailDeliveryPlugin`) |
| 8 | Reports, the report builder and statistics | WS-016 | V0.5 | `ahg-reports` (with `ReportBuilderController`; PSIS `ahgReportBuilderPlugin`), `ahg-statistics` |
| 9 | Accessions, donors and agreements; provenance and custody history | WS-014 | V1.0 | `ahg-accession-manage` (CAIS profile), `ahg-donor-manage`; provenance from `ahg-information-object-manage` (PSIS `ahgProvenancePlugin`) |
| 10 | Work assignment and description workflow | WS-014 | V1.0 | `ahg-workflow` (not its Spectrum procedures) |
| 11 | Exchange formats: EAD 3, EAC-CPF, EAC-F, RiC-O import; portable export; the Governance and Portability profiles | WS-010, WS-015 | V1.0 | `ahg-metadata-export`, `ahg-portable-export` (and PSIS `ahgPortableExportPlugin`, which does more); from `ahg-ric`: `RicDeprecationService`, `RdfImportService`, `RicDatasetService`, `RicRoundTripTest` |
| 12 | Monitoring, scheduled fixity, single sign-on, update runbooks | WS-017 | V1.0 | `ahg-observability`, `ahg-integrity`, `ahg-jobs-manage`; OIDC from `ahg-user-manage` (AtoM `arOidcPlugin`) |
| 13 | Embargoes, retention and disposal | WS-017, WS-018 | V1.0 | `ahg-extended-rights` (embargo, retention schedule, disposal), `ahg-records-manage` (after discovery) |
| 14 | IIIF manifests and viewer | WS-019 | V2.0 | `ahg-iiif-collection` (and PSIS `ahgIiifPlugin`, which does more), with openric/service #15 |
| 15 | Preservation connectors; SWORD deposit | WS-019 | V2.0 | `ahg-preservation` (PREMIS events), `ahg-ocfl`, `ahg-archivematica`; SWORD after AtoM `qtSwordPlugin` (no Heratio twin) |
| 16 | Federation and harvesting | WS-019 | V2.0 | `ahg-federation`, `ahg-resourcesync`, beside the existing OAI-PMH |
| 18 | Privacy and jurisdiction packs | V2.0 and later | V2.0 | `ahg-privacy` (POPIA), `ahg-security-clearance`, `ahg-narssa`, `ahg-naz`, `ahg-nmmz`, `ahg-cdpa`, `ahg-ipsas`, `ahg-icip` (per institution); SAHRA heritage permits after PSIS `ahgSAHRAPlugin` (no Heratio twin) |
| 19 | Research services | WS-016, WS-019 | V2.0 | `ahg-research` (decide what to take first; 79,899 lines), `ahg-researcher-manage`, `ahg-cart`, `ahg-favorites`, `ahg-feedback`, `ahg-share-link` |
| 20 | Publishing and outreach | WS-019 | V2.0 | `ahg-landing-page`, `ahg-static-page`, `ahg-articles`, `ahg-help`, `ahg-doi-manage` |
| 21 | Other GLAM domains on the RiC model | V2.0 and later | V2.0+ | `ahg-library`, `ahg-museum`, `ahg-gallery`, `ahg-dam`, `ahg-exhibition`, `ahg-loan`, `ahg-spectrum`, `ahg-3d-model`, `ahg-archaeology`, `ahg-heritage-manage` and their companions, with site records after PSIS `ahgSiteRecordPlugin` (no Heratio twin) (one domain at a time) |
| 22 | Remaining Heratio services | V2.0 and later | V2.0+ | ingest (and PSIS `ahgIngestPlugin`, which does more), scanning, labels, condition, vendors, version history, C2PA, GraphQL, SharePoint and FTP intake (copied or replaced, each by the parity register) |
| 3 | Backup, restore drill and restore verification | WS-011 | Last (deferred) | `ahg-backup` |
| 17 | AI assistance through the review queue: OCR, authority candidates, translation, search by meaning, AI chat, compliance and condition assessment; the Inferred-Provenance and Graph-Grounding profiles | WS-019 | Last (deferred) | `ahg-authority-resolution`, `ahg-translation`, `ahg-semantic-search`, `ahg-ai-services`, `ahg-ai-chatbot`, `ahg-ai-compliance`, `ahg-inference-receipts`, `ahg-provenance-ai` (output only as proposals); from `ahg-ric`: `RicProvenanceService`, `GraphGroundingService` |

: Next phases and their Heratio sources

Order of work, as Dr Johan Pieterse set it on 27 October 2026: phases 4, 5, 7 to 16 and 18 to 22 first; backup (phase 3) and all AI work (phase 17) last. The Workspace live instance holds no real data, so backup waits until a pilot does; AI work waits until the functions it would assist exist. Phase numbers are kept, as releases and the parity register refer to them. Nothing outside phase 17 may depend on an AI model.

Checked against PSIS on 27 October 2026. PSIS has 143 plugins: 124 of AHG's and 19 of AtoM's own. 118 of AHG's have a Heratio twin, some under another name, and by count of screens and actions Heratio does as much as PSIS or more for nearly all of them. PSIS does more for IIIF (81 against 51), ingest (21 against 14), portable export (18 against 11) and multi-tenancy (25 against 11); those phases read PSIS's behaviour as well as Heratio's code. Six AHG plugins have no Heratio package: email delivery (in Heratio's `app/`, phase 7), accessibility (in `ahg-core`, phase 5), SAHRA permits (phase 18), site records (phase 21), the AHG community registry (a separate product, out of scope) and the AtoM UI overrides (not needed). `governance/HERATIO-PARITY.md` lists every PSIS plugin beside its Heratio twin.

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
- **v0.0.54**: the messages written in code, about 70 status messages and the error messages people see, and Laravel's validation messages, with Afrikaans field names.
  - Errors the API answers stay in English, as do internal and operator messages.
  - `lang/af.json` has 1211 strings.
- **v0.0.55**: interface languages are chosen, not coded. An institution offers, under Settings, any language with a translation file, as AtoM's and Heratio's i18n languages setting does; English is always offered.
  - Languages are named in themselves from Heratio's list.
  - 18 more languages, 10 of them South African, carry the translations PSIS and Heratio already have, and English for the rest, until they are translated in full.
- **Still to do**:
  - full translations of the other languages;
  - Afrikaans labels for the bundled vocabularies (levels of description, entity types, relation types and the rest), from PSIS where its term is correct;
  - right-to-left layout before Arabic.

Reuse was lower than hoped: only 32 of Heratio's Afrikaans strings carried over. Heratio's strings are keyed by its own English, which is mostly AtoM's, and the Workspace's screens were written in different English.

**2. Rights statements and rights holders (WS-007).** WS-007 asks for rights on each file; today an instantiation only has free text for its rights. Copy `ahg-extended-rights`: a rights statement per instantiation from RightsStatements.org and the Creative Commons licences, as bundled vocabularies, with its basis (copyright, licence, statute, donor), its holder and its dates. The rights holder is an agent, related by a typed relation, from `ahg-rights-holder-manage`. Rights show in the public catalogue and in the files API, and go out in exports. The RiC term is checked first (`rico:Rule`, `rico:hasOrHadLegalStatus`, or a spec issue). Done when every delivered file carries its statement.

Progress:
- **v0.0.57**: rights on records and instantiations, each a rico:Rule with its basis, RightsStatements.org statement, Creative Commons licence, copyright status, jurisdiction, dates, holder and PREMIS grants. Traditional Knowledge and Biocultural Labels of Local Contexts, brought into phase 2 on 27 October 2026 (ADR 0008).
- **v0.0.58**:
  - under Manage, Rights, the rights report: in force today, by rights statement, licence and basis; the Labels applied; the rights holders; what has no right in force, each list as CSV;
  - a right or a Label given to every archival description on the clipboard, and their instantiations if chosen, after Heratio's batch assignment;
  - each authority record lists the rights it holds.
- **v0.0.59**: rights in force and Labels in the public catalogue (each record and each file), in the files API (with each PREMIS act's restriction), and in the exports: RiC-O (rico:isOrWasRegulatedBy, with the holder as rico:hasOrHadIntellectualPropertyRightsHolder), EAD3 (userestrict), Dublin Core (dc:rights) and MODS (accessCondition).
  - A holder the public may not read is not named.
  - Phase 2 is done.

**3. Backup and restore (WS-011).** `deploy/deploy.sh` already backs up the database before a release. Copy `ahg-backup`: scheduled backups of the database and the object store together, an offsite copy (its local, rsync and S3 drivers, which do not touch the AtoM schema), and a restore that checks every file's SHA-256 and the audit chain afterwards. Done when a restore drill on a fresh machine is recorded, as the G2 evidence asks. Deferred to the end (27 October 2026): the live instance holds no real data and the code is on GitHub; until a pilot holds real records, the backup before each deploy is enough.

**4. Heratio migration check (WS-013, G3).** The AtoM import reads AtoM CSV exports and AtoM's uploads. Copy the mapping rules of `ahg-data-migration` to take a difficult Heratio sample (inherited access rules, translations, relations, embargoed records). A reconciliation report lists, for each source row, what was kept, changed and lost. Done when the report for the agreed sample shows nothing lost silently.

**5. Background jobs, file text and embedded metadata (WS-007, WS-016).** Uploads and imports run in the request today. Add a database-backed queue, copying the job runner and its screen from `ahg-jobs`. Then:
- copy `PdfTextExtractService` from `ahg-pdf-tools` and the derivative and watermark code from `ahg-media-processing`, which do not touch the AtoM schema;
- copy `ahg-metadata-extraction` for embedded metadata such as EXIF and XMP;
- copy the alternative text for images from `ahg-core` (PSIS `ahgAccessibilityPlugin`, table `image_alt_text`): written by staff, shown in the public catalogue and the viewer.

Text taken from a PDF's own text layer is a proposal for the instantiation's transcript, reviewed in the queue built in v0.0.48. OCR of scanned pages is AI work and moves to phase 17. Done when an uploaded PDF produces a reviewable transcript and every image can carry alternative text.

Progress:
- **v0.0.63**: background jobs.
  - Copied from Heratio's `ahg-jobs`: the jobs record, its screen with statistics, filters, a job's detail, cancelling, a CSV export and clearing old jobs; AtoM's job tables become `background_jobs`, with types and statuses as vocabularies.
  - An upload keeps and checks its file at once and makes its viewing copies in the background; an import's dry run and import run in the background, and the import page refreshes itself until they are done.
  - The scheduler runs a worker each minute while there is work (`deploy/cron/openric-workspace`).
- **v0.0.64**: file text, embedded metadata, alternative text and watermarks. Phase 5 is done.
  - Copied from Heratio's `ahg-pdf-tools`: a PDF's own text layer, read with pdftotext in a job, is proposed as its instantiation's transcript and reviewed in the queue. A scan without a text layer gives nothing; OCR is in phase 17.
  - Copied from `ahg-metadata-extraction`: a file's EXIF, IPTC, XMP or PDF metadata, read with exiftool in a job, is kept on its master and shown to those who may receive the master. Its creator, date, title, description and copyright are proposed for the record rather than written into it.
  - Copied from `ahg-core` (PSIS `ahgAccessibilityPlugin`): every image can carry alternative text in each language, as an element of its instantiation, used as the image's alt in the catalogue and the viewer. Manage, Alternative text lists the images without it, with the coverage.
  - Copied from `ahg-media-processing`: the institution's watermark (copyright, confidential, draft or its own image) on the reference copy of each image, never on the master or thumbnail. Viewing copies can be made again after a change.
  - `workspace:files:read --as=<email>` reads the files kept before this release.
- Left for later phases: keywords, until there is a subject proposal kind; a watermark per object or by security classification (phase 18); merging TIFFs into a PDF (phase 22).

**6. Full-text search (WS-016).** Search is a substring match on names and identifiers. Copy the behaviour of `ahg-search` (query syntax, facets by level, date, repository, subject and place, highlighting, analytics), on PostgreSQL full-text search with one configuration per language, decided by ADR against Elasticsearch, which Heratio uses. Index only what the reader may see, so the public catalogue and the API search published values only. Done when the acceptance queries and the access negative tests pass.

Progress:
- **v0.0.60**: the public catalogue searches the full text of what is published, on PostgreSQL (ADR 0009).
  - Stemming in each value's language; accents ignored.
  - The search engine syntax ("a phrase", -word, OR).
  - Ranked results with the matches highlighted; facets by kind, level and repository; a range of years.
  - The index follows every change.
  - Lists in the screens are distinct rows in alternating colours, and the header's search fits a phone.
- **v0.0.61**: staff search and search statistics.
  - The Workspace header has a search box. Accounts that may see drafts search everything; others search what is published. Each result is checked against what the account may read.
  - Search statistics under Manage: what was searched most and which searches found nothing, over 7, 30, 90 or 365 days, with nothing kept about who searched.
- **Next**: search by meaning comes in phase 17; phase 6 is otherwise done.

**7. Access requests (WS-007, WS-011).** An instantiation "on request" can be opened today only by staff. Copy `ahg-access-request`: a signed-in researcher asks for a file, giving a reason; staff approve or refuse; approval gives that researcher time-limited access; each step is audited and emailed. Email is sent through a suppression gate copied from Heratio's `EmailSuppressionGate`, with bounces captured as its `EmailBounceController` does (PSIS `ahgEmailDeliveryPlugin`), so an address that bounces is not written to again. Done when a researcher's approved request opens the file and an expired one does not.

Progress:
- **v0.0.65**: email delivery and access requests.
  - Copied from Heratio's `app/` (PSIS `ahgEmailDeliveryPlugin`): every message goes through the suppression gate. A hard bounce, a complaint, or five soft bounces in 30 days, reported to the signed webhook `/webhooks/email/bounce`, hold the address. Every message sent or held back is logged. Admin, Email shows the log and the held addresses, and lifts a hold.
  - Copied from `ahg-access-request`: a signed-in researcher asks for the files of an instantiation available on request, with a reason and an urgency. Those who may review access requests for the record (a new permission, given to editors and administrators) are told by email and approve it for 1 to 365 days, or refuse it with a reason. The requester is emailed at each step, in the language they chose.
  - An approved request opens every file of the instantiation until it ends. An expired, refused or cancelled one does not.
- `ahg-researcher-manage` moved to phase 19, as Dr Johan Pieterse decided on 27 October 2026: it is researcher submissions, not a register of researchers.

**8. Reports and statistics (WS-016).** Copy the reports of `ahg-reports` (holdings by repository and level, publication status, completeness, rights, catalogue growth, collections health), with the authority completeness that already exists. Copy the usage counts of `ahg-statistics`, without storing visitors' addresses. Every report exports as CSV. Copy the report builder from `ahg-reports` (`ReportBuilderController`; PSIS `ahgReportBuilderPlugin`, 54 routes): templates, sections, saved and scheduled reports, export as Word, PDF, XLSX and CSV; its SQL queries are replaced by queries over the Workspace's own model, never free SQL. Done when a pilot archivist answers their institution's routine questions without help.

Progress:
- Copied from `ahg-reports` (its report service, collections health, catalogue growth and data quality): Reports, with its own menu.
  - The overview counts each kind of entity and how much of it is published, the archival descriptions with a digital file, growth by month, and the holdings by level and by repository.
  - Data quality counts the archival descriptions that have each ISAD(G) element, the reference code, dates and creator among them.
  - Six lists: archival descriptions, authority records, repositories, recent updates, user activity and taxonomies. They filter by date of creation or revision, publication, level of description and type of entity, page, and download as CSV. User activity needs the audit log's permission.
  - Not copied: the accession and donor reports, which come with phase 9, and the physical storage report, which the containers and physical storage pages already cover. Heratio's lists start at the last month; the Workspace's show everything until a date is chosen.
- Copied from PSIS's `ahgStatisticsPlugin`, which Heratio's `ahg-statistics` reads: usage statistics of the public catalogue, under Reports.
  - Each page viewed and each file downloaded is counted (a picture shown in a page is not a download). Web crawlers, known by a vocabulary taken from PSIS's list of bots, are counted apart.
  - The statistics show totals, each day, the most viewed and downloaded, and the counts by repository and by web crawler, for any period, repository or item. Each day and the most viewed download as CSV.
  - No visitor's address, user agent, referrer or session is kept, so there is no count by country. A visitor is a hash under a salt that changes each day and is then forgotten.
  - The events are counted into daily counts each hour and kept 90 days (`WORKSPACE_USAGE_RETENTION_DAYS`); the daily counts stay.
- Copied from PSIS's `ahgReportBuilderPlugin` and Heratio's `ReportBuilderController`: the report builder, under Reports.
  - A report is on one kind of entity. Its columns are the elements of that kind's standard with the fixed fields (name, reference code, level, repository, type, published, created, updated), never a table or SQL.
  - It is filtered and sorted, and made of sections: summary, table, counts (by level, repository, type, publication or year) and text, moved up and down.
  - A report has a status from a vocabulary, is its owner's or shared with those who may read its kind, and can be copied. Three templates are bundled, and any report can be saved as the institution's template.
  - Each report downloads as CSV, XLSX, PDF (Dompdf) and Word (PhpWord), with table headings bold white on dark green.
  - Schedules make a report daily, weekly, monthly or quarterly at a time in `WORKSPACE_TIMEZONE`, keep the file, and email it through the Outbox to accounts of the institution that may read its kind, never to typed addresses. Every file made is kept to download again, and removed with its report.
  - A share link opens a report without signing in. It always expires (at most 365 days), can be revoked, counts its openings, and only its hash is kept.
  - A report's definition and sections are kept as numbered versions and restored, what it was before being kept first.
  - Not copied: comments, attachments, links, widgets, snapshots of the data, the cover page and the review workflow of PSIS's builder.

**9. Accessions and donors (WS-014).** Copy `ahg-accession-manage`, which follows the Canadian CAIS profile (`CaaisProfileService`), and `ahg-donor-manage`. An accession register records what arrived, from whom, when and on what terms. The donor is an agent with contact points; the agreement is a record. RiC models an accession as an activity, so this needs an ADR on the mapping, and on keeping donor contact details encrypted as Heratio does. Donor agreements come with `ahg-donor-manage` (PSIS `ahgDonorAgreementPlugin`): types, documents, history and the accessions and records each covers. Provenance and custody history follow, copied from `ahg-information-object-manage` (PSIS `ahgProvenancePlugin`: provenance events, agents and documents): in RiC each change of custody or ownership is an activity with its agents, so the same ADR maps it. Done when a pilot archivist registers a deposit under its agreement, links it to the fonds it became, and the fonds shows its custody history.

Progress:
- **v0.0.77** (9a): accessions, after Heratio's `ahg-accession-manage` and its CAAIS profile: the accession is its own kind, exported as a rico:Activity, the donor an authority record taking part in it (ADR 0010); CAAIS rows, deaccessions, the CSV and CAAIS exports.
- **v0.0.78** (9b): donor agreements, a rico:Record of their own, with rights, restrictions, reminders and documents, after PSIS's `ahgDonorAgreementPlugin`.
- **v0.0.79** (9c): the intake of accessions: PSIS's workflow, checklist, attachments, timeline, queue and numbering.
- **v0.0.80** (9d): appraisal, valuation, containers and rights of accessions, and Heratio's whole add form.
- **v0.0.81** (9e): provenance and custody history, after Heratio's provenance in `ahg-information-object-manage`: the overview, the chain of steps (each a rico:Activity affecting the record, with its owner taking part, and its own dates), documents, the timeline and CSV. A description made from an accession starts its chain from the donor and the repository. Phase 9 is done.

**10. Work assignment and workflow (WS-014).** Copy the task model of `ahg-workflow`: tasks on records, assigned to people, with due dates, overdue notices and bulk assignment. Its Spectrum procedures are museum work and stay out. The task states form a vocabulary, and publication remains the existing review and publish step. Done when a team divides a fonds' description among its members and sees what is left.

Progress:
- **v0.0.82** (10a): workflows and their steps, tasks on records claimed from the pool or assigned, decided, returned and resubmitted, my tasks, team work by role, history and bulk operations, after Heratio's `ahg-workflow` and PSIS's `ahgWorkflowPlugin` (ADR 0011). A workflow started for a fonds and everything inside it divides the work, and the fonds shows what is left.
- **v0.0.84** (10b): Heratio's queues and PSIS's service level policies, each task's standing, the morning run that warns, notices overdue tasks and escalates, the overdue list and its CSV, publish gates on the publication page with an audited override, the requirement of an approved workflow before publishing, publish readiness and the workflow diagram. Phase 10 is done.

**11. Exchange formats (WS-015).** The Workspace imports AtoM CSV and EAD and exports EAD, Dublin Core, MODS, CSV and RiC-O. To add:
- EAD 3;
- EAC-CPF import (export exists);
- EAC-F, which openric/service #14 asks for;
- RiC-O import, with exception reports and dry runs like the existing import.

The crosswalks of `ahg-metadata-export` are copied. With them, two OpenRiC profiles the Workspace does not yet declare:
- **Governance**: a deleted entity's IRI answers as deprecated (`owl:deprecated`, `dcterms:isReplacedBy` its successor, the reason), never as missing, from a deprecation register; copied from Heratio's `RicDeprecationService`. Today a deleted record simply disappears from the API.
- **Portability**: the RiC-O import above with a round-trip test, a DCAT and VoID dataset descriptor and a change feed; copied from `RdfImportService`, `RicDatasetService` and `RicRoundTripTest`.

These profiles came from ArchiveHeritageGroup/OpenRiC #1 to #3, now in the specification. `ahg-portable-export` (an offline catalogue for a USB stick or a reading room) is copied with light change. Done when each format round-trips the demonstration data, every rejected field is listed, and nothing is lost silently.

On 30 October 2026 Dr Johan Pieterse split the phase: 11a the archival exchange formats, 11b the RiC-O import with the Portability and Governance profiles, 11c the offline catalogue.

Progress:
- **v0.0.85** (11a): EAC-CPF import and EAC-F export and import, each one file or a ZIP of files, with exports of every authority record or activity as a ZIP (ADR 0012). EAC-F is the EAS 2026-07 release's EAF 1.0.0, and the export is valid against its schema. A record the Workspace exported reads back with nothing written; each relation keeps its direction; every element the import does not read is listed. EAD3 export and import, with their round trip, were already in place.

**12. Monitoring and operations (WS-017).**
- Copy `ahg-observability` for health and metrics.
- Copy `ahg-integrity` for fixity checks on a schedule, with reports of failures.
- Copy `ahg-jobs-manage` for the operator's view of jobs.
- Copy single sign-on through OpenID Connect from `ahg-user-manage` (AtoM `arOidcPlugin`), per institution, mapped to the Workspace's accounts and roles.
- Write runbooks for updating and rotating keys; the restore runbook comes with phase 3.

Done when the security, accessibility and disaster-recovery sign-offs of WS-017 have their evidence.

**13. Embargoes, retention and disposal (WS-017, WS-018).** Copy the embargo service, notices and reports of `ahg-extended-rights`; agents' embargoes already exist, and this extends embargoes to records and files. Its retention schedule and disposal workflow, with `ahg-records-manage`, follow a discovery with a records manager. Disposal is a reviewed, audited activity that leaves a record of what was destroyed. Done when a disposal is approved, carried out and reported, with nothing removed outside it.

**14. IIIF (WS-019).** Copy the manifest building of `ahg-iiif-collection`: a IIIF Presentation 3 manifest for each record from its reference copies, a collection for each fonds, and a viewer in the public catalogue. Follow the RiC and IIIF pattern of openric/service #15 as specification guidance. PSIS's `ahgIiifPlugin` has more than Heratio's package (81 routes against 51, among them IIIF authentication); read it for what Heratio lacks. Done when the manifests validate and the MCP's image tool can follow them.

**15. Preservation connectors (WS-019).**
- Copy PREMIS events from `ahg-preservation`: ingest, fixity, migration.
- Copy the OCFL storage layout from `ahg-ocfl`.
- Copy the Archivematica transfer and status from `ahg-archivematica`.
- Accept deposits by SWORD, as AtoM's `qtSwordPlugin` does; Heratio has no twin, so this is written fresh against the existing import.

Each one is optional per institution. Done when an instantiation's preservation history can be exported as PREMIS.

**16. Federation and harvesting (WS-019).** Copy `ahg-federation` (shared search across Workspace institutions and peers, one visitor key per peer, as the MCP does) and `ahg-resourcesync`, beside the OAI-PMH that already exists. Done when two institutions search each other's published catalogue and neither sees the other's drafts.

**17. AI assistance (WS-019). Deferred to the end (27 October 2026).** All AI work is here, so nothing before it depends on a model: OCR of scanned pages (from phase 5), authority candidates, translation, search by meaning (after phase 6), the AI chatbot and AI compliance (from phase 22), AI condition assessment, inference receipts and the provenance of AI outputs. Copy the candidate ranking of `ahg-authority-resolution`, so the review queue offers the likely authority record for a proposed name. Copy `ahg-translation` for proposed translations and `ahg-semantic-search` for search by meaning. All of it runs through the AI gateway and produces proposals only, as ADR 0007 decided. With it, two more OpenRiC profiles:
- **Inferred-Provenance**: what a model asserted is exported as inferred (`prov:wasGeneratedBy`, confidence), from the assertion origins the Workspace already records; copied from `RicProvenanceService`.
- **Graph-Grounding**: `GET /ground`, disambiguation packs keyed to entity IRIs, for agents such as the MCP; copied from `GraphGroundingService`.

These came from ArchiveHeritageGroup/OpenRiC #4 and #5. Done when every machine output in the Workspace can be traced to its proposal and its reviewer.

**18. Privacy and jurisdiction packs.** Copy `ahg-privacy` (POPIA: personal information in descriptions, requests for access and correction), `ahg-security-clearance` and national packs such as `ahg-narssa`, `ahg-naz` and `ahg-nmmz`, `ahg-cdpa`, `ahg-ipsas` and the rest of `ahg-icip` (Traditional Knowledge labels came in phase 2). SAHRA heritage permits under the National Heritage Resources Act follow PSIS's `ahgSAHRAPlugin`, which has no Heratio twin: a researcher applies, a supervisor endorses, the authority decides. Each is enabled per institution and decided by ADR. Done when a South African institution can show POPIA compliance for its catalogue.

**19. Research services.** Copy the reading-room side: a researcher's cart and favourites (`ahg-cart`, `ahg-favorites`), feedback on records (`ahg-feedback`), time-limited share links that respect access rules (`ahg-share-link`), and researcher submissions (`ahg-researcher-manage`: descriptions and files a researcher drafts for staff to review, through the existing review queue). `ahg-research` is the largest Heratio package, at 79,899 lines; what of it the Workspace takes is decided by ADR before it is copied. Done when a researcher can find, collect, request and cite material in one visit.

**20. Publishing and outreach.** Copy institution landing pages, static pages and articles (`ahg-landing-page`, `ahg-static-page`, `ahg-articles`), in-application help (`ahg-help`), and DOIs minted for published records (`ahg-doi-manage`, with the DOI pointing at the record's persistent IRI). Done when an institution runs its public site from the Workspace.

**21. Other GLAM domains on the RiC model.** Heratio also serves libraries, museums, galleries and digital asset collections. RiC-O describes records, so each domain starts with a mapping decision (RiC with domain extensions, or a mapping to CIDOC CRM, Linked Art or BIBFRAME, which openric/service #16 researches), recorded by ADR before its copy. Then one domain at a time:
- library: `ahg-library`, `ahg-biblio-bf`, `ahg-biblio-frbr`, `ahg-z3950`, `ahg-mods-manage`;
- museum: `ahg-museum`, `ahg-spectrum`, `ahg-condition`, `ahg-loan`, `ahg-exhibition`, `ahg-artwork-request`;
- gallery and digital assets: `ahg-gallery`, `ahg-dam`, `ahg-3d-model`, `ahg-image-ar`;
- archaeology and heritage sites: `ahg-archaeology`, `ahg-harris-matrix`, and site records for archaeological, heritage and rock art sites after PSIS's `ahgSiteRecordPlugin` (no Heratio twin; locality shown by role);
- heritage assets: `ahg-heritage-manage`, with heritage accounting (PSIS `ahgHeritageAccountingPlugin`).

Done for each domain when its Heratio functions work on the RiC model and export in its own standard.

**22. Remaining Heratio services.** Whatever the parity register still lists as open after phase 21 is copied, or replaced by something the Workspace already does. That includes ingest (reading PSIS's `ahgIngestPlugin`, which does more) and scanning workflows, box labels, vendors, version history, C2PA, GraphQL, and SharePoint and FTP intake. The AI chatbot, AI compliance and inference receipts moved to phase 17. Done when the parity register has no open row.

Not copied: the 12 templates on the prohibited-copy list, until each is compared with AtoM's own; the Workspace's own theme, menus and accounts already do their work. Everything else in Heratio is a candidate to copy.

## Next actions

1. Name the product, archival domain, standards, technical, legal and provenance, and security leads; recruit one pilot institution (G0).
2. Freeze the V0.1 demonstration scenario and sample dataset, including one relationship whose evidence can change.
3. Run the Heratio source audit and the relational, rights and provenance spikes, each recorded as an ADR (G1).
4. Estimate the V0.1 work by role and dependency after the spikes; publish only conditional targets.
