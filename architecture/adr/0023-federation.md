# ADR 0023: Federation, signed search between catalogues, all of ahg-federation

- Status: accepted
- Date: 2026-11-04
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 16 copies Heratio's `ahg-federation`, about 15,800 lines, and `ahg-resourcesync`, beside the OAI-PMH the Workspace already has. It adds Sparnatural, the visual SPARQL query builder FranceArchives and the Archives nationales use over RiC-O. The roadmap's test: two institutions search each other's published catalogue, and neither sees the other's drafts.

`ahg-federation` holds:

- peers and the signed search between them;
- OAI-PMH harvest and the union catalogue;
- DSpace and Alma connectors;
- vocabulary sync and term mapping;
- the network directory and join requests, and federation governance;
- a Europeana EDM export;
- loan requests between institutions.

On 4 November 2026 Dr Johan Pieterse chose:

- **All of it** ("All of it (Recommended)"), for field-for-field parity, as phase 19 took all of `ahg-research`, split 16a to 16e, one release each;
- **Fuseki, already running** ("Fuseki, already running (Recommended)"), as the triple store for Sparnatural. Apache Jena Fuseki serves the OpenRiC reference service on this host; the Workspace gets a dataset of its own, fed only with published RiC-O.

The split:

- 16a: peers, signed federated search and what an institution shares;
- 16b: OAI-PMH harvest, the union catalogue, the DSpace and Alma connectors;
- 16c: vocabulary sync and term mapping, peer discovery, the network directory and join requests, governance;
- 16d: the Europeana EDM export and loan requests;
- 16e: ResourceSync, the Fuseki dataset and Sparnatural.

## 16a: peers and signed federated search

Each institution has its own peers, set by who may manage it. A peer is one of:

- another institution on this installation;
- another OpenRiC Workspace;
- a Heratio;
- from 16b, an OAI-PMH, DSpace or Alma catalogue.

**What an institution shares.** Nothing until it chooses to. Then only what is published, as the public reads it, and only the levels of description it names, or every level. Heratio could share unpublished records; the Workspace never does.

**Answering a peer.** A peer searches at `/federation/search?q=&limit=` on the institution's host and gets JSON in Heratio's shape: `total`, and `results` each with `id` (the IRI), `title`, `description`, `referenceCode`, `levelOfDescription`, `type`, `url` and `score`. It is CORS-open. An institution that does not share answers 403.

**Signing.** Every answer is signed with the installation's one Ed25519 key, the audit signing key, as Heratio signs with its one key. The signature is detached, over the SHA-256 of the exact bytes sent, hex, in `X-Federation-Signature`, with `X-Federation-Key-Id` and `X-Federation-Sig-Alg: ed25519-sha256-hex`.

The key is published at `/.well-known/openric-federation-key` in Heratio's key document form. `/open-data/protocol` names the key, the search and the peer index; `/open-data/federation` lists the institution's peers, as Heratio's index does.

**Searching the peers.** An institution searches its peers at once:

- an institution on this installation is read here, as the public sees it, so no institution ever sees another's drafts;
- any other is asked over HTTP, in parallel, each within its own time limit, with its API key (encrypted) where it asks for one.

What they answer is merged by score and the peer's priority, one row for an item two peers both hold. Each result names its peer and whether its answer was verified. Each peer's answer is cached for fifteen minutes, and every search is logged with how many peers answered, timed out or failed. The public catalogue offers the search of other catalogues beside its own.

**Trust.** A peer's answer is verified against the key it publishes, found from its `/open-data/protocol`, else the Workspace's or Heratio's well-known address. The key is pinned on first use. A peer that later signs with another key is not trusted, and its answer is not used, until an operator clears the pin. A peer whose address is not a public one is refused when it is added and never asked, as Heratio's SSRF guard refuses it.

What was changed from Heratio:

- An institution on the same installation is a peer like any other.
- Only published records are ever shared.
- A peer's API key is encrypted, and in key rotation.
- The signing key is the audit signing key the Workspace already keeps, since it has no inference-receipts key.

### Not carried in 16a

- Heratio's `federation_peer_search.search_api_url` and `search_api_key` as a second row: they are the peer's own search address and key, one row.
- Heratio's `published_only` switch: always on.
- Heratio's `min_level_id`: the levels shared are named instead, since the Workspace's levels have no order to count from.

### Evidence (16a)

- `tests/Feature/FederationTest.php`:
  - two institutions on one installation:
    - nothing found from one that does not share, and a 403 to a peer that asks it;
    - once it shares, its published unit found and never its draft, in the search and the public catalogue's page;
    - a level not shared not given;
  - the peer-facing side:
    - an answer signed with the institution's key, which its published key document verifies over the exact bytes;
    - the protocol descriptor naming the key, and the peer index;
  - a remote Workspace's signed answer:
    - verified and its key pinned on first use, its API key sent, the same search answered from the cache;
    - an answer signed with another key refused, and the pin cleared by the institution;
  - a peer at a private address refused when added, and an institution refused as its own peer.

## 16b: OAI-PMH harvest, the union catalogue, DSpace and Alma

**Harvest**, after Heratio's `HarvestClient` and `HarvestService`. An OAI-PMH peer is harvested on its interval, each hour, or when asked: from its last harvest, or in full. Its records are listed by ListRecords, page after page by resumption token, in its metadata format (`oai_dc` unless set) and set.

Each record is taken in as a draft unit of description, at item level, in a repository the harvest makes for the peer ("Harvested from <peer>"). The Dublin Core is mapped as Heratio maps it, onto the ISAD(G) elements:

- the title, and the description as scope and content;
- the rights as conditions governing access, and the format as extent;
- the date as the dates expressed, and the source as a note;
- the first identifier that is not an address as the unit's identifier.

A record harvested before is brought up to date. One the peer marks deleted is deleted here, as any unit is, its IRI answering 410. A busy repository's 503 is waited for as its Retry-After says.

Each run is kept with its counts and any error, and each record's action is in the harvest log. Each unit is tied to the record it came from by peer and OAI identifier.

What was changed from Heratio:

- The peer must be at a public address, checked before the first request, and redirects are not followed.
- The tie between a unit and its source record is a table, not properties on the unit.
- Only a unit the harvest made is ever changed or deleted by it.
- A harvested unit stays a draft until someone publishes it, so nothing harvested reaches the public catalogue or a peer unseen.

**The union catalogue**, after Heratio's `UnionCatalogueService` and `UnionHarvestService`. An institution's union has members, itself among them; each is opted in, off by default.

- **Its own records.** Its published units of description, of the levels it shares, become its self member's union records, each night or when asked. They leave the union when it stops sharing.
- **Its members' records.** Each other member's are fetched from that member's `/union/records`, asking only for its own (`self=1`), so two unions that list each other do not copy each other's copies.
- **Serving it.** The union is served at `/union/records` as paged JSON, Heratio's shape, CORS-open: every enabled member's records, or only the institution's own with `self=1`.
- **Searching it.** By title in the public catalogue and on the federation page.

**DSpace and Alma**, after Heratio's `DSpaceConnector` and `AlmaConnector`, are searched beside the other peers:

- **DSpace 7 or later**, by its REST discovery API: `/server/api/discover/search/objects`, or the REST address given.
- **Alma**, by SRU 1.2 in MARCXML: 245 for the title, 100, 110 or 700 for the author, 264, 260 or the 008 for the date, 520 for the summary, and 001 as the MMS id in the record address the peer gives.

Their answers are unsigned, and say so.

### Not carried in 16b

- Heratio's `oai_heritage` and `oai_ead` formats: they are Heratio's own; Dublin Core is what every repository gives. A Workspace or Heratio peer is better searched by the signed federation API (16a).
- Heratio's AtoM Elasticsearch connector: it reads AtoM's index on the same server, which the Workspace never does.

### Evidence (16b)

- `tests/Feature/HarvestAndUnionTest.php`:
  - harvest:
    - a faked OAI-PMH peer harvested over two pages into three draft units in the peer's repository, mapped from Dublin Core, none public;
    - the next harvest asking from the last, bringing one up to date and deleting one as the peer says, each action in the log;
    - an OAI-PMH error failing the run with its reason;
  - the union:
    - nothing in it until the institution shares and opts in, then its published unit and not its draft;
    - a member's records fetched with `self=1`;
    - the union served whole and as the institution's own, and found in the public and staff searches;
    - its records gone when it stops sharing;
  - a faked DSpace repository and Alma catalogue found beside each other by their own protocols, Alma's record address from its template, both unsigned.

## 16c: governance, discovery, vocabulary sync and joining

**Governance**, after Heratio's `FederationGovernance`. Each peer can have:

- **A trust level.** An untrusted peer is never asked. A peer at the verified level must sign every answer, or the answer is dropped.
- **A rate limit.** A peer asked again within its limit is skipped until the limit passes; a search it answered within fifteen minutes is still served from the cache.
- **The surfaces it may be asked for:** search, the union, vocabularies.

An institution can also require that every answer be signed by the peer's pinned key, dropping the rest, as Heratio's `require_verified` does.

**Discovery**, after Heratio's `PeerDiscoveryService`. Each Workspace and Heratio peer's `/open-data/protocol` is probed each night or when asked. The probe keeps:

- whether the peer answers the Federation Query Protocol, is not compliant, or is unreachable;
- its protocol version, the surfaces it declares, and its maturity grade where it publishes one;
- its whole federation block.

The Workspace's own descriptor now names its surfaces and the addresses of its search, union, vocabularies, network directory and join form.

**Vocabulary sync**, after Heratio's `VocabSyncService`.

- **Offering ours.** An institution offers the vocabularies of its own it names, as SKOS Turtle at `/federation/vocabularies/{id}.ttl`, listed at `/federation/vocabularies`, with each change to them since a time at `/changes?since=` (created, updated, deprecated), as Heratio's `federation_vocab_change` keeps them.
- **Taking a peer's.** A peer's vocabulary is taken into one of the institution's own, on the sync's interval or when asked, through the same SKOS import every local vocabulary has. A concept taken in gets the institution's IRI and keeps the peer's as an exact match: that exact match is Heratio's `federation_term_mapping`, kept where the Workspace already keeps concept matches.
- **Conflicts.** A concept the institution has already keeps its labels (keep ours) or takes the peer's (take theirs). Each run is kept with what it added, updated and found the institution had.

**The network directory and joining**, after Heratio's `NetworkDirectoryService` and `JoinRequestService`.

- `/open-data/network` lists the institution, the peers it federates with that it does not distrust (with their trust level, discovery status, surfaces and maturity), its union's members, and the join form.
- An archive asks to join at `/federation/join`; a field no person fills in turns robots away, and the form is limited to five requests a minute.
- The institution's administrators approve a request, which makes the archive a peer trusted at the basic level, or decline it with a reason.

What was changed from Heratio:

- An institution offers only the vocabularies it names.
- Term mappings are the concept matches the Workspace already keeps, so a mapping is visible on the concept itself and exported with it.
- Vocabulary sync does not push changes to peers; each peer pulls them from the change list. This is Heratio's push direction, offered rather than sent.

### Not carried in 16c

- Heratio's trust dossier and authenticity addresses (`trustDossierUrl`, `authenticityUrl`): they point at Heratio's AI compliance pages, which are phase 17.
- Heratio's `federation_enabled` switch: a peer's `is_active` does the same.

### Evidence (16c)

- `tests/Feature/FederationGovernanceTest.php`:
  - governance: an untrusted peer not asked; a peer asked again within its rate limit skipped; an unsigned answer dropped once the institution requires verification;
  - discovery: three peers probed, one answering the protocol with its version, surfaces and maturity grade, one not compliant, one unreachable;
  - vocabulary sync:
    - a peer's vocabulary taken into the institution's own, each concept with the institution's IRI and the peer's as an exact match;
    - taking theirs replacing a label, keeping ours leaving it;
    - the institution's shared vocabulary listed, given as Turtle with a new concept, and its change listed since a time;
  - joining: a robot's request kept from the queue, a request approved into a basic peer, and that peer listed in the network directory.

## 16d: the Europeana export and loans

**The Europeana export**, after Heratio's `EuropeanaExportService` and `EdmSerializer`. The institution's published units of description, or those changed since a day, are each written in the Europeana Data Model:

- an `edm:ProvidedCHO`: title, reference code and IRI as identifiers, scope and content, creators, subjects and names, genres, languages, dates, places, the EDM type, rights, extent and the holding repository;
- its `ore:Aggregation`: the data provider, `isShownAt` its persistent IRI, `isShownBy` its reference or access copy, `object` its thumbnail, and `edm:rights`;
- an `edm:WebResource` for each file the public may receive;
- the `edm:Agent`, the `edm:Place` with its WGS84 position, and the `edm:TimeSpan` it names.

The EDM type follows Heratio's `deriveEdmType`: IMAGE, SOUND, VIDEO or 3D by the files' types, else TEXT. `edm:rights` is the RightsStatements.org or Creative Commons IRI of the right in force on the record (ADR 0008), else the institution's default.

The files and a sitemap of them go in one ZIP bundle, kept in an OCFL object of its own and downloaded with its SHA-256. Each run is kept with its count and any error. The institution sets its data provider name (its own name unless set), country, language and default rights.

What was changed from Heratio:

- Only what the public reads goes in, decided by ExportView as every export is. Heratio leaves out a record's digital objects only where it has redactions; here files the public may not receive are never named.
- The bundle is kept in OCFL, not left in a folder.

**Loans**, after Heratio's `LoanRequestService` and `LoanAnalyticsService`. A loan is a request between two members of the institution's union, the institution among them, to borrow an item for a purpose and a window of dates.

- **The item.** Where the institution holds it, the item is its own unit of description; otherwise the holder's reference and title.
- **Transitions.** A loan moves only along Heratio's transitions: requested to approved, declined or cancelled; approved to in transit or cancelled; in transit to returned. Each move is recorded with who made it, when, and a note.
- **The figures.** Loans by status; how many the institution asked of others and others of it; the share approved of those decided; the average days to a decision; and the members and items most asked for.

What was changed from Heratio: only who administers the institution moves a loan, and the database keeps a loan's window in order and its two members apart.

### Not carried in 16d

- Heratio's EDM `dc:identifier` from the slug: the Workspace has no slugs; the persistent IRI is the identifier.
- Delivery to Europeana itself: Europeana takes the bundle in through its own aggregator's ingestion, outside the Workspace.

### Evidence (16d)

- `tests/Feature/EuropeanaAndLoansTest.php`:
  - Europeana:
    - a published unit with its creator, a place with coordinates and an image exported as EDM, with its title, IMAGE type, creator and Agent, Place position, isShownAt its IRI and isShownBy its reference copy;
    - the right in force given rather than the default;
    - a draft left out, and the bundle with its sitemap downloaded with its SHA-256;
  - loans:
    - a loan of the institution's own unit requested by a member, a move out of turn refused, a member borrowing from itself refused, and a window ending before it begins refused;
    - the loan approved with a note, sent and returned;
    - a second declined, and the figures counting both, out and in, at half approved.

## 16e: the triple store, the SPARQL endpoint, Sparnatural and ResourceSync

**The triple store.** The Workspace keeps its published RiC-O in a dataset of its own on an Apache Jena Fuseki server. Fuseki already runs on this host for the OpenRiC reference service, and Dr Johan Pieterse chose it on 4 November 2026.

- Each published unit of description, authority record, activity, place, rule and repository is a named graph, and the graph's name is the entity's IRI. Its content is the RiC-O the public API gives for it, turned from JSON-LD into N-Triples and written by the SPARQL 1.1 Graph Store Protocol.
- A sync takes what changed since the last one. It reaches a quarter of an hour further back, because a search entry is stamped with the time its transaction began, and writing a graph again changes nothing. A deprecated entity's graph is dropped. A full sync, run each night, also drops any graph under the institution's base IRI that is no longer published.
- Only what is published goes in. The store never holds a draft, so nothing that queries it can find one.
- Nothing is switched on until `WORKSPACE_SPARQL_URL` is set.

**The SPARQL endpoint.** `/sparql` takes a query by GET or POST and forwards it to the dataset, with a time limit and an open CORS header. Only SELECT, ASK, CONSTRUCT and DESCRIBE are forwarded; a query that carries an update form is refused before it reaches the store. The store's own update user is never exposed.

**Sparnatural.** Sparnatural 14.0.0 (LGPL-3.0) is served from `public/vendor/sparnatural/` and embedded at `/discover/explore`, and it sends its queries to `/sparql`. Its configuration, `/sparnatural/config.ttl`, is not written by hand. It is built from the pinned OpenRiC shapes:

- each RiC-O or OpenRiC class a shape targets is an entity;
- a property constrained to another such class is a link;
- a literal is searched as text, or as a date where its datatype is one;
- the title or name is the label.

The relation types add their links, each by its RiC-O property between the RiC-O classes of its kinds and named as the type is. Yasgui is not used, as the roadmap says.

**ResourceSync**, after Heratio's `ahg-resourcesync`, at the same addresses: the source description at `/.well-known/resourcesync`, the capability list, the resource list, and the change list.

- Every published entity is listed by its persistent IRI with when it last changed. The change list marks each as created or updated since `from`, and lists withdrawn entities as deleted.
- As in Heratio, the change list reaches thirty days back unless asked, and both lists are paged by `?page=` at 1,000 a page, each page linking the next and the one before.
- Changed from Heratio: an entity is named by its IRI rather than its slug, and a deletion comes from the deprecation record, so a removed entity is still announced as removed.

### Not carried in 16e

- Creating the Fuseki dataset and setting live's `.env`. These are an operator's steps (`INSTALL.md`, Optional parts), done on Dr Johan Pieterse's word.
- A ResourceSync resource dump or change dump (ZIP packages). Heratio has none either.

### Evidence (16e)

- `tests/Feature/SparqlAndResourceSyncTest.php`:
  - the store:
    - a full sync puts the published repository and unit, each as its own graph signed in with the store's user, and never the draft;
    - it drops the deprecated entity's graph and a graph no longer published;
    - an incremental sync puts what changed and never the draft, and the console command reports each institution;
  - the endpoint:
    - a SELECT and an ASK are forwarded and answered, with CORS;
    - INSERT DATA, DELETE WHERE, a query followed by DROP ALL, and an empty request are refused, and none of them reaches the store;
    - with no store set up, the endpoint answers 503 and the explorer says the graph is not open;
  - Sparnatural: the configuration parses as Turtle, with entities for Record, RecordSet, Person, CorporateBody, Place and Activity, a record named by its title and linked to a person, and is served from the cache;
  - ResourceSync:
    - the source description and capability list link each other;
    - the resource list holds the published entities and not the draft;
    - the change list holds the changed unit and the withdrawn entity as deleted, holds nothing from a moment later, reaches thirty days back by default, and refuses a date it cannot read;
    - one entity a page links the next page and the one before.
