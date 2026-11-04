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
