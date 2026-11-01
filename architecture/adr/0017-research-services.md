# ADR 0017: Research services: all of Heratio's ahg-research

- Status: accepted
- Date: 2026-10-31
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 19 copies Heratio's research services: `ahg-research` (79,908 lines, 431 routes, 57 services), `ahg-researcher-manage`, `ahg-cart`, `ahg-favorites`, `ahg-feedback` and `ahg-share-link`. The roadmap asked for an ADR to decide how much of `ahg-research` to take.

`ahg-research` is two things in one package:
- a reading room: registration, rooms, seats, bookings, walk-ins, the retrieval queue, equipment, custody, reproductions, saved searches, citations and bibliographies, and a researcher's collections, annotations and notebooks
- a scholarly research studio: projects, teams, collaboration, the writing, review, publication and method studios, grants and funding, data management plans, ethics, ORCID, replication packs, the claim ledger, the argument builder, the contradiction engine, the time machine, the AI copilot and disclosure, field alerts, lectures, training, offline and mobile

On 31 October 2026 Dr Johan Pieterse chose **all of it** over the reading room alone, to be built end to end and released once.

## Decision

The phase is built in parts, each finished whole, and released together:

- **19a** favourites, the cart and shop, feedback, share links
- **19b** researcher registration and the reading room: rooms, seats, bookings, walk-ins, the retrieval queue, equipment, custody
- **19c** reproductions, citations, bibliographies, saved searches
- **19d** the researcher's workspace: collections, annotations, notebooks, the research diary, research workspaces
- **19e** researcher submissions (`ahg-researcher-manage`) through the review queue
- **19f** projects, teams, collaboration, milestones, outputs, project export
- **19g** the studios: writing, review, publication, method, question builder, argument builder, source triage; research journals, their issues and articles, and the directory of target journals
- **19h** funding, grants, data management plans, ethics, impact, ORCID, replication packs
- **19i** analysis: the claim ledger, contradiction engine, time machine, cross-fonds queries, entity resolution, the RiC bridge, field alerts, the decision log, research memory, analysis results, snapshots, timelines and maps, analytics and reports. Built before 19g, whose studios rest on its claims
- **19j** lectures, training, quotas, offline and mobile, notifications, document templates, ODRL policies, assessments

Amended on 1 November 2026: 19a to 19g and 19i are released together in v0.0.95; 19h and 19j are parked, and phase 23 (hardening and acceptance) comes first, as Dr Johan Pieterse decided after two external reviews (ROADMAP.md). The parity register keeps their rows as "With 19h" and "With 19j".

The AI parts of `ahg-research` (the copilot, AI disclosure and AI decisions) go to phase 17 with the Workspace's other AI, which is deferred to the end; AI runs only through the gateway.

Research data is a researcher's own working data, not catalogue description. So its forms carry no RiC term (ADR 0006, option C), except where a record's own data is kept, as on share links.

## Decision (19a)

- **Favourites** (`ahg-favorites`):
  - Any signed-in account keeps any entity of the Workspace (Heratio keeps records, authority records, repositories, accessions, terms, functions and physical objects), or a link of its own, with notes, in folders.
  - A favourite is shown only while its owner may read it.
  - Bulk move and remove, search, sorting, and clearing all.
  - A folder is shared by a link that expires, or does not when it is public. The link is shown once and kept as a hash, where Heratio keeps the token itself. A shared folder shows a visitor only what the public may read.
  - Exports: CSV (with a byte order mark), JSON, BibTeX, RIS, EndNote XML, CSL-JSON, Heratio's minimal EAD 3, a page to print, and PDF of it.
  - Import takes one persistent IRI a line, where Heratio takes slugs.
  - Sending favourites to a researcher's collection, project or bibliography comes with those, in 19c to 19f.
- **Citations** (from `ahg-research`'s CitationService and CitationExportService, brought forward for the exports):
  - One record or many, in RIS, BibTeX (Heratio's cite keys), EndNote XML, CSL-JSON, APA 7, MLA 9 and Chicago 17.
  - A record is read through ExportView, so a citation gives only what its reader may read.
- **Share links** (`ahg-share-link`):
  - Only who may change a record issues one. The expiry is 14 days by default and at most 90, or 365 for who may manage the institution. A limit on uses is optional, and a recipient and a note are kept.
  - The recipient sees the record as its issuer may read it, drafts included when the issuer may see them, decided again at every use. A link never opens more than its issuer may read, and opens nothing once the issuer no longer may.
  - Files only when the issuer allowed them and may still receive them.
  - Every use and every refusal (expired, revoked, used up) is logged, and a download only once its file is sent.
  - Revoking takes a reason. A daily run notes links that have expired, and deletes links that ended a year ago and log rows older than 180 days, as Heratio's prune does.
  - The token is kept as a hash and shown once.
  - Heratio's clearance check for classified records comes with security clearance (18c).
- **Feedback** (`ahg-feedback`):
  - Anyone sends feedback on the catalogue or a record: a type (general, bug, feature, correction, compliment; vocabulary rows), a subject, remarks, name, surname, email, telephone and relationship to the record.
  - A hidden field and a rate limit keep robots out.
  - Staff who may change descriptions read it, add notes, mark it completed or delete it. Heratio lets administrators only; corrections are the cataloguers' work.
- **The cart and the shop** (`ahg-cart`):
  - Records go into a cart. A visitor's is kept by a key in the session and joins the account on signing in, as Heratio's mergeGuestCart does. Checking out needs an account, since requests and notices go to accounts; Heratio also lets a guest check out.
  - **With the shop closed**, checking out asks for each record as Heratio's request to publish does: an access request of type publish, which staff answer.
  - **With it open**, an order is placed at the prices of the product types, VAT included, numbered ORD-YYYYMMDD-NNNN. Product types and their prices are reference data of the institution, seeded with Heratio's nine. Each says whether it is digital, needs shipping and which file it delivers.
  - The buyer pays through PayFast. Heratio's cart never sent the buyer to PayFast; its marketplace did, and that code is copied.
  - A PayFast notification is trusted only when its signature, its source address (outside the sandbox), PayFast's own validation, its amount and its merchant all hold. A live shop with no passphrase refuses every payment, as Heratio's does. Every notification is kept, with why it was refused.
  - A free order is paid at once.
  - A paid digital item gives downloads of the file its product delivers, for some days and a number of times, never past an embargo, screening or a community protocol. Download tokens are kept as hashes.
  - Who may manage the shop (a new institution permission, given to administrators) sets it up, prices its products and handles its orders.
  - Heratio keeps Stripe's keys but never charges with them, so Stripe is not carried. Its marketplace routes belong to `ahg-marketplace`, out of scope.

## Decision (19b)

- **Researchers** (Heratio's research_researcher, its registration, renewal and admin controllers):
  - An account registers as a researcher: name, affiliation (vocabulary rows), institution, department, position, student number, research interests and current project, ORCID iD, Web of Science and Scopus identifiers, ISNI, identity document and number, a photograph and a kind of researcher. The telephone and identity number are encrypted, as Heratio encrypts them.
  - Who may manage the reading room (a new institution permission, given to administrators) approves, rejects with a reason or suspends. A rejected researcher may register again. Approval lasts the type's months, else a year, and gives a reader's card number (R-YYYY-NNNNN). A type that requires it cannot be approved before the identity is verified; a type that approves by itself does so.
  - Checks of identity and affiliation are recorded with their document; a verified identity document marks the identity verified.
  - A daily run warns a month before a registration ends and ends it when it has. An expired researcher asks to renew.
  - Heratio files an access request for each approval; here the staff list holds the registrations waiting. Each decision is told to the researcher through the Outbox. The researcher audit table is the audit log.
- **Kinds of researcher** (research_researcher_type): their limits on booking ahead, hours a day and records a visit, how long a registration lasts, whether the identity must be verified, and whether they are approved without review. Heratio seeds none, so neither does the Workspace.
- **Reading rooms** (research_reading_room): hours, the days they open, capacity, walk-ins and their capacity, rules, booking ahead, hours and the notice to cancel, a floor plan, and the repository they serve. Seats (research_reading_room_seat), added one by one or in runs, with their kind and fittings. Retrieval runs (research_retrieval_schedule) for a day or every day, with a cut-off. Equipment (research_equipment) with its kind, condition and maintenance (research_equipment_maintenance).
- **Bookings** (research_booking). Heratio keeps the limits but checks none when a booking is made. The Workspace does:
  - an approved researcher books a day the room opens, within its hours and its booking ahead
  - for no longer than the room and their type allow in a day
  - while the room has space
  - with no more records than their type allows
  - having accepted the room's rules

  The researcher cancels up to the room's notice; staff cancel at any time. Staff confirm, check in (with a seat, the one chosen or the first free), check out (the seat released and what was in use returned, as Heratio does) or mark a no-show. The researcher is told through the Outbox, as Heratio mails them.
- **Requests for records** (research_material_request, research_request_status_history, research_request_correspondence, research_custody_handoff, research_request_queue):
  - A request notes where the record is kept, from its storage, and the last retrieval run before the visit whose cut-off has not passed.
  - It goes requested, retrieved, delivered, in use, returned (or unavailable, with the reason), each step logged and moving where it is. One that needs a curator's approval is not fetched before it.
  - Staff set its priority, queue, who fetches it, its handling and its location, print call slips, record each hand-over with its condition and signature, and check a return.
  - The researcher and staff write about it; staff notes stay hidden from the researcher.
  - Queues show the requests of chosen statuses, room and priority, in a chosen order.
- **Walk-ins** (research_walk_in_visitor): while the room takes them and has space today, having accepted its rules; linked to a researcher with the same email address.
- **Equipment bookings** (research_equipment_booking): an approved researcher books working equipment within the room's hours, its maximum and when it is free. Staff hand it over and take it back; returned damaged, it needs repair until maintenance is recorded.
- **Files** (a researcher's photograph, verification documents, floor plans and message attachments) are kept by checksum in the file store, as agreement documents are.
- Heratio's triage status, which nothing on its screens sets, is not carried.

## Decision (19c)

- **Reproductions** (research_reproduction_request, research_reproduction_item, research_reproduction_file):
  - An approved researcher whose kind may ask for them makes a request, numbered REP-YYYY-NNNNN, with its purpose, intended use, publication details and delivery. It holds copies of records: the kind (photocopy, scan, photograph, digital copy, transcription, certification), format, resolution, colour, how many, which pages and instructions.
  - A request with no copy is not sent. Sending it estimates its cost as Heratio's calculateCosts does, from the institution's prices (seeded with Heratio's): a base and so much a page, image, file or document; colour half as much again; 600 dpi and more a quarter again.
  - Staff take it on, ask for payment with an invoice (INV-YYYYMM-NNNNN), record the payment (which puts it in production), finish each copy with its files, and complete it once no copy is left unfinished, or cancel it with a reason. Every step is logged and told to the researcher.
  - A delivered file is downloaded by its researcher from the request for 30 days, as Heratio's are; its token is kept as a hash.
  - Payment is recorded by staff, as in Heratio; it is not taken through the shop.
- **Bibliographies** (research_bibliography, research_bibliography_entry):
  - Any signed-in account keeps bibliographies (Heratio: a researcher), each in a citation style: Chicago, APA, MLA, Harvard, Turabian, ISAD(G); vocabulary rows.
  - Entries are records of the catalogue (their facts as the owner may read them) or other works with Heratio's fields: typed in, sent from favourites, or imported from BibTeX, RIS or a pasted citation with Heratio's parsers.
  - Exported as RIS, BibTeX, Zotero RDF, Mendeley JSON, CSL-JSON or written out in their style; shared by a link shown once and kept as a hash.
- **Citing a record** (Heratio's cite page and CitationService): any reader cites a record they may read in each style, or downloads it for a reference manager; each citation is logged (research_citation_log). Harvard, Turabian and ISAD(G) are added to the styles of 19a.
- **Saved searches** (research_saved_search, research_search_alert_log):
  - Any signed-in account keeps a search of the Workspace's index, its words and filters, run with the owner's access. It runs again; a snapshot of its results is kept, and what came and went since is shown.
  - Heratio keeps whether a search alerts and how often but sends nothing. Here a daily run takes each search whose turn has come (daily, weekly, monthly), tells its owner of new results through the Outbox and logs the run.

## Decision (19d)

- **Collections** (research_collection, research_collection_item):
  - Any signed-in account keeps collections (Heratio: a researcher). An item is any entity of the Workspace, as favourites are, or an outside address (Heratio's external_uri), with its notes, tags, reference code and place in the order.
  - Records are added from their page (to a collection chosen, or a new one named there, as Heratio's createCollectionAjax does) or by their persistent IRIs. An item is shown only while its owner may read it.
  - A collection is shared by a link shown once and kept as a hash; a visitor sees only what the public may read. Heratio's finding aid of a collection is a page to print, each record with its reference code, title, dates, extent, repository and scope and content as the owner may read them.
- **Annotations** (research_annotation, research_annotation_target):
  - Notes, highlights, bookmarks, tags and transcriptions on any entity the author may read, or on a part of it, with a title, text, tags and a collection.
  - Where an annotation points is a W3C Web Annotation selector, a vocabulary row: a region of an image (FragmentSelector, xywh), a point, a quote with what comes before and after (TextQuoteSelector), a range of text, a stretch of a recording, or an SVG shape. The annotation studio (Heratio's annotationStudio) shows a record's images the public may see, and a region dragged across one fills in the selector.
  - Who reads one: its author; when shared, the members of the research workspaces its author belongs to; when public, every signed-in account of the institution; always only while they may read the entity.
  - An entity's annotations one may read are given out as a W3C AnnotationPage (JSON-LD), each with its motivation, text, tags and selector. Heratio's iiif_annotation_id is the annotation's address in that page.
  - Heratio's evidence viewer (a record's notes and the tags of its annotations) is the studio's list and tags.
  - Changed: Heratio keeps HTML content, cleaned; here content is plain text.
- **Notebooks** (research_notebook, research_notebook_item): notes, records pinned to the notebook (by IRI), and saved searches of one's own, each pinned to the top or not and kept in order, with a record as the notebook's cover. An item of AI output is written by the AI services of phase 17, through the gateway; a researcher cannot add one by hand.
- **The research diary** (research_journal_entry):
  - Dated entries with a title, text, time spent, tags and kind (entry, reflection, milestone), filtered by kind, dates and words, with the time added up.
  - Heratio names the kinds the system writes (auto_booking, auto_material, auto_annotation, auto_search, auto_collection) but nothing writes them. Here a booking, a record asked for by the researcher, an annotation, a saved search and a collection each leave one, in the researcher's name; the researcher may change its text but not its kind.
  - An entry is private unless its author says otherwise; one not private is read by the members of the research workspaces its author belongs to, on the workspace's page.
- **Research workspaces** (research_workspace, research_workspace_member, research_workspace_resource, research_workspace_file):
  - A researcher makes one, private, for its members, or public (read by the institution), and invites colleagues of the institution by e-mail address with a role; they accept or decline, through the Outbox notice and the list of invitations.
  - Roles rank as Heratio's canAccess ranks them: owner, administrator, editor, viewer; contributor sits beside editor and member beside viewer. A contributor adds collections, bibliographies and saved searches of their own, links and files, and removes only what they added; an editor removes what others added; an administrator invites, removes and changes roles; the owner may hand the workspace to an accepted member and become an administrator, and alone deletes it. The owner neither leaves nor is removed.
  - What a member opens through it (a collection, a bibliography, a saved search, a file) is shown with the member's own access.
  - Files are kept as the other research files are: checked, scanned and stored by checksum.
  - Heratio's share_token is not kept: one joins by invitation.
- **Later in phase 19:** Heratio's project_id on collections, annotations and diary entries, and promoting a notebook to a research project, come with projects in 19f; so do discussions and the evidence comments of collaboration. Workspace storage quotas come with 19j.

## Decision (19e)

- **Researcher submissions** (Heratio's `ahg-researcher-manage`, completed from PSIS's `ahgResearcherPlugin`, whose SubmissionService, PublishService and ExchangeImportService Heratio copies only in part: its submit, resubmit, publish and delete-item routes are named in its screens but not defined):
  - Any signed-in account drafts a submission: a title, a description, the repository and the record its descriptions go under. Its items are descriptions (title, identifier, level of description - the bundled levels or the institution's own - dates as written and from and to, extent and medium, scope and content, creators, subjects, places, genres, conditions of access and reproduction, notes), one inside another; notes on records already in the catalogue (by IRI); new repositories (name, address, contact); and new creators (name, history, dates of existence). Descriptions have files, kept as the other research files are.
  - A submission is also made from one of the researcher's collections, or from an exchange file made offline (PSIS's researcher-exchange.json, format 1.0: notes, files, new items one inside another, new creators and new repositories, with files carried as base64; a file named but not carried is kept by name, as PSIS keeps a placeholder). Changed: from a collection, each record becomes a note on that record with the researcher's notes on it; PSIS copies them as new descriptions, which would describe them twice. An exchange note names its record by persistent IRI; one that names none is kept for the researcher to point before sending.
  - Sent for review, it needs a description or a note, and each note its record and text; it can then not be changed until it is returned.
  - Who may review submissions (a new institution permission, given to administrators) sees the queue of those sent, under review and approved; takes one on, comments, returns it with the reason, rejects it with the reason, or approves it. Every step is logged on the submission, and the researcher is told by e-mail.
  - **Publishing.** PSIS writes AtoM's tables itself. Here the approved descriptions are written as AtoM CSV, each item's id its legacyId, and run through the Workspace's import as the reviewer: matched, checked, made and reported as any import is, with their access points, creators (as creation events) and dates. A dry run first shows what would happen; the full report is the import's own. The reviewer chooses the repository, the language, whether the new descriptions are published at once (PSIS publishes them), and whether new subjects, places and genres may be made. New repositories and creators are made before the descriptions, so the import finds them by name. Then each description's files become instantiations of the record made of it, and each note is added to its record's notes after what is there. A submission becomes published once something of it is in the catalogue; until then it stays approved, to try again.
  - The review is the submission's own steps, as in PSIS. The Workspace's workflows move catalogue entities; the records a submission makes then follow its publication rules.
  - Its research project (Heratio's project_id) comes with 19f.

## Decision (19f)

- **Research projects** (research_project, research_project_collaborator, research_project_resource, research_activity_log; Heratio's ProjectService and its projects and collaboration pages):
  - Any signed-in account makes a project, with its kind (thesis, dissertation, publication, exhibition, documentary, genealogy, institutional, personal, public, other), status, who may see it (private, collaborators, public: read by the institution), its institution, supervisor, funding source, grant number, ethics approval and dates.
  - Collaborators are invited by e-mail address with a role, and accept or decline. Roles rank as Heratio's canAccess ranks them: owner, editor, contributor, viewer. A contributor adds and removes only what they added; an editor changes the project, invites, removes and removes what others added; the owner may hand the project over and alone deletes it.
  - Its resources are one's own collections, saved searches, annotations and bibliographies, records of the catalogue, links (with the kind of site), documents and notes. What a collaborator opens through it is shown with their own access.
  - A collection, annotation, diary entry, bibliography or submission is put in a project by its owner, who must be a contributor to it; so is a research workspace file, by who added it. This is Heratio's project_id on those tables.
  - A clipboard of records, each with its notes and pinned or not, for each collaborator.
  - Every change is written to the project's activity log.
  - Heratio makes a share link that opens nothing. Here it opens the project's summary (team, milestones, outputs) to anyone holding it; it is shown once and kept as a hash. Heratio's institutional shares and external collaborators are not carried: nothing in Heratio writes or reads them.
- **Milestones** (research_project_milestone and research_milestone, two tables in Heratio): one table with the fields of both: kind, description, deliverable, due date, completion date and by whom, status, progress and order. A milestone not completed or cancelled is overdue after its date and due soon within 14 days, as in ResearchMilestoneService.
- **The team** (research_team_member): people who need not have an account, with their role, institution, e-mail address, ORCID iD, lead, contribution and dates. An ORCID iD is taken bare or as its link, and refused unless its check digit holds (ISO 7064 11,2; Heratio checks only its shape). A member whose e-mail address is an account of the institution is linked to it.
- **Outputs** (research_output): articles, datasets, software, presentations, theses, reports and chapters, with authors, venue, date, status and an identifier (DOI, Handle, ISBN, URL) resolved to its address as Heratio's resolveUrl does. Heratio's dmp_id comes with data management plans in 19h.
- **Notebooks promoted** (NotebookService::promoteToProject): a notebook becomes a public project once; its records become a collection of the project and its notes the project's notes.
- **Working together** (CollaborationService, CollaborationRealtimeService, research_comment):
  - Discussions of a project or research workspace, with replies; members write, the author or who manages it (an editor of a project, an administrator of a workspace) changes, resolves or deletes; who manages it pins.
  - Comments on the evidence of a project: the project as a whole, one of its collections, or an item of one. The project page polls every 20 seconds, as Heratio's does, for new comments and for who has had the project open in the last 90 seconds. Changed: Heratio polls by the last comment's number; the Workspace's ids are not numbers in order, so it polls by time.
  - Comments on an annotation, diary entry or collection, by who may read it; its owner is told by e-mail. Comments on reports come with reports in 19i.
- **Export** (ProjectExportService): the project as Markdown or JSON, its sources (its records, cited as the exporter may read them, and the entries of its bibliographies) as BibTeX, RIS or CSL-JSON, or all of it as a ZIP file; each export logged. Heratio's bundle also carries the question brief, claims, decision log, argument, method protocols and research memory; those sections join the export as 19g and 19i bring them.
- **Later in phase 19:** Heratio's project outputs page (knowledge graph, assertions, hypotheses, snapshots, timeline, map and network builders) is analysis, in 19i; RO-Crate, the reproducibility pack, DOI minting and the ethics milestones and compliance dashboard come with 19h; extraction jobs are AI, in phase 17; the project journey (Heratio's Command Centre) spans all of research and comes with 19i.

## Decision (19i)

- **The claim ledger** (research_assertion with research_claim_meta, research_assertion_evidence; ClaimLedgerService and the assertions pages):
  - A project's claims, in words or as an assertion about an entity (its subject, a predicate, and what is asserted, of a kind: biographical, chronological, spatial, relational, attributive), with a status (Heratio's idea, working, supported, contested, weak, rejected, needs more evidence, publishable, and the assertions' proposed, verified, disputed, retracted), a confidence and its level, and the ledger's fields: the kind of evidence, where the claim comes from, sources for and against, quotations, the link to method and theory, notes, unresolved weaknesses, ethical concerns.
  - Changed: Heratio keeps a claim in research_assertion and its ledger fields in a sidecar; here they are one table. A claim's version rises when its words or status change.
  - Evidence for or against a claim is a record (by IRI), an item of one of the project's collections, an annotation, a bibliography or a work in it, or another claim; never the claim itself, never twice. The ledger shows the claims with no evidence and those resting on one source alone, as Heratio's claimsWithoutCitation and claimsOverDependent do. An editor reviews proposed assertions in bulk.
  - The knowledge graph (Heratio's knowledge graph and network graph, both drawn from the assertions) is the claims' subjects and objects as nodes and their predicates as edges, as a table and JSON.
- **Hypotheses** (research_hypothesis, research_hypothesis_evidence): a statement, its status (proposed, testing, supported, refuted), tags, and evidence supporting, refuting or neutral, with a confidence; their count kept.
- **The contradiction engine** (ContradictionEngineService): a scan of the claims by Heratio's four rules: opposite positions on one topic (two shared key terms or more), one source supporting one claim and refuting another, a weakened claim still sure of itself or a confidence off its level, and one leading term in two opposite claims that share little else. Each finding is kept once by a signature and found again by the next scan; dismissed or resolved by the project. Fixed: Heratio's shared-source rule looks for evidence that "opposes", which its evidence never is, so it never fired; its key terms dropped letters such as ê and ë. Its AI deepening is in phase 17.
- **The decision log** (research_decision_log) and **research memory** (research_memory_item): decisions by kind (scope change, exclusion, hypothesis revision, method pivot, question reformulation, supervisor instruction), with the reason, who decided and when; and what a researcher carries forward (unresolved questions, future articles, unused sources, abandoned hypotheses, reusable data, collaborators, conferences, grants), suggested from decisions as Heratio suggests them, and gathered across projects when carried forward.
- **The analysis bridge** (research_analysis_result, research_analysis_result_claim, research_analysis_code): a chart, table, theme or statistic made outside the Workspace, with its data and version, method, code, date, the researcher's reading of it and its file, linked to the claims it supports, weakens or puts in context; and the project's theme tags and memos. Heratio's suggestCaption is AI, in phase 17.
- **Snapshots** (research_snapshot, research_snapshot_item): the project's records as the researcher may read them, each with its IRI, title, reference code, a SHA-256 of its description and whether it was public or under an embargo, and a SHA-256 of the whole; frozen, to cite, and not taken again; two compared for what came, went and changed. Changed: Heratio's snapshot is a title, its items never filled.
- **The timeline and map** (research_timeline_event, research_map_point): events typed in or taken from the dates of a collection's records; points with coordinates on the earth and dates, as GeoJSON.
- **Source assessments** (research_source_assessment): a record judged as a source: primary, secondary or tertiary; its form; how complete it is; trust from 0 to 100; why; its bias.
- **Entity resolution** (EntityResolutionService): a researcher proposes that two entities of one kind are one, with a confidence, method and evidence; who may change both decides, after seeing the claims that relate the two. Accepted, two authority records are offered for merging, which stays the catalogue's own act.
- **Searching across fonds** (CrossFondsQueryService): the Workspace's index, with the searcher's access, kept to the fonds chosen, each result named with its fonds; logged. Changed: Heratio queries Elasticsearch once for each fonds.
- **Field alerts** (FieldAlertService): a project watches the works it cites by DOI, from its bibliographies or by hand; each day Crossref and OpenAlex are asked about each, by its DOI only, for retractions, updates and new citing works; each alert raised once.
- **Reports** (ReportService, ResearchReportsController, research_peer_review): a report of its kind, its first sections from PSIS's templates (Heratio keeps the table but seeds none), written, moved and filled from the project, shown to print; peer reviewers of the institution accept, decline or give their feedback and a rating from one to five, and the author is told. Comments may be on a report or a section. Heratio keeps HTML; here, plain text.
- **The RiC bridge** (RiCBridgeService): a project described in the catalogue as a rico:Activity, dated, performed by an authority record, with its published outputs as activities that are part of it, updated when done again. Changed: Heratio does it for every project as soon as it is made; here it is the act of someone who may create authority records, for a public project, and the activities are drafts until published. Heratio's own notes flag its output-to-project relation for refining; here it is part of an activity.
- **Analytics** (ResearchAnalyticsService): researchers, visits, records asked for, reproductions, citations, saved searches, records collected and projects, for a period, with the most asked for, cited and collected, for who runs the reading room. Changed: counted from the services' own tables, so Heratio's research_statistics_daily is not kept.
- **The time machine** (TimeMachineService): the project's dated events by month, and the project as it stood on a day. It writes nothing.
- **Not carried:** Heratio's validation queue and extraction jobs review and run the output of AI extraction, which is phase 17; research_quality_metric is filled by OCR and image services that are phase 17 or the Workspace's own preservation; research_collaboration_session is never written.

## Decision (19g)

- **The writing studio** (WritingStudioService): documents in sections; a claim or a work of the project's bibliographies pulled into a section as Heratio writes them; each version kept whole; given out as Markdown.
- **The review studio** (ReviewStudioService): reviewers' comments on the project or a claim, in threads, resolved or not. Its AI reviewer twin is phase 17.
- **The publication studio** (PublicationStudioService): a manuscript sent to a venue of the directory or named, moved only as Heratio's transitions allow, dated when sent and decided; the venue's requirements (Heratio's seven, and more) ticked off; reviewers' points with the replies and revisions. Venues are suggested by the project's words, as Heratio's suggestForScope does. Changed: its table is research_publication_submissions, as researcher_submissions are submissions to the catalogue.
- **The method studio** (MethodStudioService): a protocol from one of Heratio's eleven method templates, its thirteen areas written with the template's prompts.
- **The question builder** (QuestionBuilderService): the research design brief, each save a new version never changed, with the reason; diagnosed by Heratio's checks (too broad, too narrow, gap not stated, method thin, ethically sensitive, evidence unrecorded, publishable). Its AI diagnosis is phase 17.
- **The argument builder** (ArgumentBuilderService): the nine steps from problem to implication on claims of the ledger, moved, with Heratio's warnings.
- **Source triage** (SourceTriageService): the works and items of the project sorted by Heratio's eleven categories and marked by how far they are read. Its AI preview is phase 17.
- **Research journals** (ResearchJournalService): a researcher's or the institution's journal, or a manuscript for an outside one; issues; articles with a Markdown body (shown with any HTML escaped) and their word count, checked against their target journal as Heratio's validateManuscript checks them. Heratio's body_html is not kept: the HTML is made from the Markdown when shown.
- **The directory of target journals** (ResearchTargetJournalService), moved here from 19h as journals and the publication studio need it: the journals researchers aim at, kept by who runs the research services, with Heratio's starter list of South African journals accredited by the DHET.
- **The project's export** carries the research brief, claims, decision log, argument, method protocols and the exporter's research memory, as Heratio's does.

## Evidence (19a)

`tests/Feature/ResearchServicesTest.php` covers:

- favourites in folders, moved, exported (BibTeX, RIS, EAD, PDF), shared by a link that shows only what the public may read, unshared, imported by IRI
- a share link issued only by who may change the record, capped in time and uses, showing an unpublished record as its issuer reads it, giving files only when allowed, revoked, pruned, its events logged
- feedback sent by a visitor, refused when a robot fills the hidden field, and completed by staff
- a closed shop's checkout asking for each record
- an open shop: a visitor's cart joining the account, prices with VAT, an order paid through PayFast after a forged and a wrong-amount notification are refused, the paid download given only to the buyer and only for its file, a free order paid at once, and the order's status changed by who manages the shop

## Evidence (19b)

`tests/Feature/ReadingRoomTest.php` covers:

- a registration encrypted, refused booking until approved, approved only once the identity is verified, with its card number and end date; another rejected and registered again; a warning and the end of a registration, and a renewal
- a booking refused on a closed day, outside the hours, for too long, too far ahead and without the rules; one accepted with its record and retrieval run; the room full; a cancellation refused to the researcher within the notice and made by staff
- the desk's day: confirmed, a curator's approval before fetching, retrieved and delivered, a step refused, checked in to the first free seat, handed over and signed for, a staff note hidden from the researcher, checked out with everything returned and the seat released, the status log and a call slip
- walk-ins up to the room's limit, and equipment booked within its maximum, not twice at once, returned damaged and repaired

## Evidence (19c)

`tests/Feature/ResearchToolsTest.php` covers:

- a reproduction refused to who is not an approved researcher and for post without an address; refused to be sent empty; estimated (scan of 10 pages, colour, 600 dpi: 28.13); refused a staff step by the researcher; invoiced, paid, refused completion with a copy unfinished, finished with its file, completed, its steps logged; the file downloaded by its researcher only
- a bibliography with a record, a book, BibTeX, RIS and a citation imported; the record refused twice; exported in each format and written out in Chicago; shared by a link; favourites sent to it
- a record cited in each style for the public, downloaded as RIS and logged; an unpublished record cited only by staff
- a saved search showing a new result since its snapshot, alerting its owner once a week, and refused to anyone else

## Evidence (19d)

`tests/Feature/ResearcherWorkspaceTest.php` covers:

- a collection made from a record's page, the record not added twice, a link refused unless it is a web address, an import by IRI that adds nothing new, an item's notes and tags, the finding aid, the collection hidden from another account, and the link showing a visitor only what the public may read, then withdrawn
- annotations with a region, a quote and none; malformed regions and a script for a shape refused; the public one read by another account, the shared one only once that account shares a research workspace with the author; the W3C AnnotationPage with its motivation, source, selector and tags; deleted only by the author
- a notebook with a cover, a note, a pinned record and a saved search; a missing record, someone else's saved search, AI output and an empty note refused; pinned items first
- the diary: entries with time spent, an entry that cannot pretend to be the Workspace's, the Workspace's own entry for a saved search, filters and the time added up, changes only by the author, a kind the Workspace wrote kept, and an entry not private read by a fellow member of a research workspace
- a research workspace: an invitation refused for an unknown address and the owner role, sent by e-mail, answered only by the invitee; a viewer refused to add; a contributor adding their own collection, a link and a file, refused someone else's collection and refused removing what others added; the collection and file opened by another member and refused to a non-member; the owner unable to leave, handing the workspace over, then leaving; a public workspace read but not changed by the institution; deleted by its new owner

## Evidence (19e)

`tests/Feature/ResearcherSubmissionTest.php` covers:

- a submission with a fonds and an item inside it (levels, dates, access points, extent), a creator and a note; a note on an unknown record, an unknown level, dates backwards and a description put inside what is inside it refused; a file on the item; hidden from another account and the queue refused to a researcher
- sent, then refused changes; returned only with a reason, and the researcher told; changed and sent again; taken on, refused publication before approval, approved
- a dry run that makes nothing and reports two descriptions to be made; the publication: the descriptions made one inside the other in the repository, the file their instantiation, the creator an authority record, the note added after the record's own; the researcher told; the published submission not deleted or published again
- a submission from a collection, its record a note with the researcher's notes; an exchange file of the wrong version and one that is not JSON refused; one with notes, descriptions one inside another, a creator, a repository, a file carried and one only named, and a kind it does not know; a note without its record keeping it from being sent; its file given to its researcher only

## Evidence (19f)

`tests/Feature/ResearchProjectTest.php` covers:

- a project hidden from others; dates backwards and the owner role refused; a collaborator invited by e-mail and accepting; a contributor refused an invitation of their own
- resources: their own collection, a record, a document and a note added by a contributor, someone else's collection refused, a link removed only by the owner; a document and a collection opened through the project
- a milestone overdue until completed, then at 100% with who completed it and when; a team member's ORCID iD refused for its check digit and taken from its link, another linked to their account; an output's DOI resolved; the clipboard, pinned only by who put the record on it; the activity log
- the project public: read by the institution, not added to; a share link refused to a contributor, its summary shown to anyone, then withdrawn
- one's collection and bibliography put in the project, someone else's refused; the export as Markdown, BibTeX (the records and the bibliography's entries), JSON and ZIP, each logged, and refused to who may not read it
- a notebook promoted: a public project with a collection of its records and its notes; promoted only once
- a discussion with a reply, pinned only by who manages it, resolved; a research workspace's discussion, refused to a non-member
- comments on the evidence, on a collection of the project only, polled with who is looking, until they leave; a comment on a public annotation, its author told, and refused on a private one; resolved by its subject's owner and not deleted by another

## Evidence (19i)

`tests/Feature/ResearchReasoningTest.php` and `tests/Feature/ResearchAnalysisTest.php` cover:

- claims with a subject entity and ledger fields; evidence by record, collection item and claim, refused for itself, twice and outside the project; unsupported claims shown; versions rising; the subject kept on editing; the knowledge graph; proposed assertions reviewed only by an editor
- the contradiction engine finding all four kinds, keeping each once, a dismissed one staying dismissed
- hypotheses with evidence and their count; the decision log by kind; memory suggested from a decision, kept once, carried forward, deleted only by its owner; an analysis result with its file and claim, its file refused to a non-member
- snapshots with checksums and rights, compared after a change, frozen to cite and not taken again
- the timeline from a collection's dates, once; a point off the earth refused; GeoJSON; field alerts with Crossref and OpenAlex answered for a retraction, an update and a citing work, each raised once
- a source assessment; entity resolution refused for one entity or two kinds, decided only by who may change both, offering the merge; a search kept to one fonds and logged; the RiC bridge refused to a researcher and for a private project, made once and updated, with its performer and part
- a report from PSIS's finding-aid template, filled, a section moved, printed, hidden from others; a peer review asked of the institution, not of the author, given with a rating, the author told, a comment on the report; analytics for the reading room's staff

## Evidence (19g)

`tests/Feature/ResearchStudiosTest.php` covers:

- the research brief in versions, earlier ones unchanged, and its diagnosis; a method protocol from the archival template, its areas kept to the template's; the argument's steps and warnings
- a document with a claim and a work pulled in, a version kept, given out as Markdown; review comments in a thread, resolved, refused to a non-member; venues suggested; a manuscript moved only as allowed, dated, with a reviewer's point; source triage filtered
- the time machine and the export with the brief and claims
- the DHET starter list added by staff only, twice without doubling; the directory filtered; a manuscript journal with an issue and an article checked against its target and shown with its HTML escaped, hidden from others

