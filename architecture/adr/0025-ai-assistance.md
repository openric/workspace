# ADR 0025: AI assistance, through the gateway and only as proposals

- Status: accepted
- Date: 2026-11-05
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 17 holds all of the Workspace's AI work, deferred to the end on 27 October 2026 so that AI assists functions that already exist. It is 12 Heratio packages, about 147,000 lines:

- `ahg-ai-services`, `ahg-semantic-search`, `ahg-c2pa` and `ahg-authority-resolution`, the largest;
- `ahg-translation`, `ahg-ai-chatbot`, `ahg-ai-compliance`, `ahg-rdm`, `ahg-provenance-ai` and `ahg-inference-receipts`;
- the AI parts of `ahg-ric` and `ahg-search`.

To these the roadmap adds Annif for subjects, and two OpenRiC profiles, Inferred-Provenance and Graph-Grounding. ADR 0007 decided that every machine output is a proposal for a person to review, and the host's standing rule is that every model call goes through the AHG AI gateway at `ai.theahg.co.za`.

On 4 and 5 November 2026 Dr Johan Pieterse chose, asking for the best long-term options:

- **The split, 17a to 17g**, one release each:
  - 17a: the gateway client, inference receipts, AI provenance and OCR;
  - 17b: names found in text, summaries, authority candidates, condition and handwriting;
  - 17c: translation;
  - 17d: search by meaning and `/ground`;
  - 17e: Annif;
  - 17f: the chatbot and AI compliance;
  - 17g: C2PA and research data management.
- **Off until a gateway key is given.** Long term the build is better checked against the gateway's real answers, so a dev key was asked for; until it comes, the gateway is faked in the tests.
- **Annif behind the gateway.** The Workspace side is built against an `/ai/v1/annif` route, and the gateway route was asked for in ArchiveHeritageGroup/ahg-ai#4.
- **pgvector** for search by meaning, so that a search can filter by institution, publication and access in the same query.

For 17b, on 5 November 2026, he chose:

- **Condition:** a vision model through the gateway's existing chat route now, replaced by a trained condition model when the gateway has one. That route was asked for in ArchiveHeritageGroup/ahg-ai#5; Heratio's own condition check points at a node directly and was never finished.
- **Handwriting:** built once a dev gateway key lets its real answer be checked.
- **Authority resolution:** all of `ahg-authority-resolution`, adapted to RiC.

17b is split into 17b1 (names, summaries and condition) and 17b2 (authority resolution).

## 17a: the gateway, the log of inferences and OCR

**The gateway client** (`App\Ai\Gateway`) is the one door to a model.

- **When it refuses.** It refuses unless the installation has a key (`AI_GATEWAY_KEY`), the institution has switched AI on (`ai_settings.enabled`), and it is under its monthly limit if it set one.
- **What it records.** Every call, answered or not, is recorded.
- **What it never does.** A node's own address is never used. A model's answer is never written to a description: what it says becomes a proposal.

**The log of inferences**, after Heratio's `ahg-provenance-ai` and `ahg-inference-receipts`, is the table `ai_inferences`, one row a call. Each row holds:

- the service (the `ai-service` vocabulary) and the model and its version;
- the gateway address;
- what went in and what came out, each hashed with SHA-256 and kept as an excerpt;
- the confidence, what it was about, who asked, how long it took and whether it was answered.

Each row is also a receipt:

- it holds the hash of the one before it and is signed with the installation's Ed25519 key, the audit log's key, canonicalised by RFC 8785 as the audit chain is;
- a trigger refuses any change or deletion;
- `workspace:ai:verify`, or **Check every receipt**, walks the chain and names the first receipt missing, out of order, changed or wrongly signed.

Heratio keeps the record and the receipt in two places; here they are one row.

**Proposals name their inference**:

- `proposals.inference_id` links a proposal to the call that made it;
- `proposals.accepted_value` keeps what the reviewer wrote;
- so a correction is traced without Heratio's separate override table. Every machine output can be traced to its proposal and its reviewer, which the roadmap sets as the phase's test.

**OCR of printed pages** copies Heratio's vision reading (heratio#1522). On the Brenthurst Library cuttings it scored 95.0% real words, against 89.1% for Tesseract alone and 94.0% for Tesseract corrected by a language model. It works like this:

- The page is cut into strips at its whitest rows.
- Each strip is read by a vision model through the gateway's Ollama chat route at temperature 0.
- Each reading is checked against Tesseract, run locally. A strip that disagrees is read again by a fallback model, and one that still disagrees is flagged.
- The text is proposed as the instantiation's transcript. Its confidence is the share of strips that passed, its evidence names the flagged strips, and it links to the first strip's receipt; every strip is a receipt.

The models are `qwen2.5vl:7b-ctx16k` and `qwen3.6:27b` unless the institution names others. Handwriting is left to 17b, where the gateway's HTR route can be checked against its real answers.

**The Inferred-Provenance profile**, after Heratio's `RicProvenanceService`, applies to RiC-O relations. A relation that a model asserted (assertion origin `model`) is exported as `prov:wasGeneratedBy` a `prov:Activity`, associated with the model as a `prov:SoftwareAgent`. A relation that no model asserted carries nothing, and by that absence is documented fact. The `prov` prefix joins the JSON-LD context.

**Tests.**

- `tests/Feature/AiFoundationTest.php`:
  - nothing is asked without a key, with AI off or over the limit;
  - each call, answered or refused by the gateway, is a receipt chained to the one before, which `workspace:ai:verify` checks;
  - a change is refused by the table and found by the verifier when made around it;
  - a page is read with the strip that disagreed with Tesseract read again, proposed with its confidence, evidence and receipt, and corrected and accepted, the trace naming the reviewer and the correction;
  - only administrators set AI up;
  - a relation a model asserted is exported as generated by it.
- `tests/Feature/AccessMatrixTest.php` builds a receipt, and every new address refuses another institution.

## 17b1: names, summaries and condition

`App\Ai\Assist` asks the gateway on behalf of a person who may update the description. It runs as the `AskModel` background job (job type `ai-assist`), and each answer becomes proposals that name their receipt.

**Names**, after Heratio's `NerService`:

- **The call.** The unit's description values and the transcripts of its pages, at most 20,000 characters, go to `/ai/v1/ner/extract` (`{text, clean}`).
- **The answer.** `entities_v2` (value, type, offsets, score) is read, or else the `entities` buckets. PERSON, ORG, GPE and LOC, and DATE are kept; other kinds are dropped.
- **The proposals.** They go through `ProposalService::propose()`, the API's path, with the passage as evidence and spaCy's score as the confidence only where it gives one. `propose()` now takes the inference id.

**Summaries**, after Heratio's `LlmService::summarize` and its prompt:

- The transcripts go to `ollama/api/chat` with `think: false`, in the description's language.
- The model is `ai_settings.chat_model`, else `qwen3:8b`.
- `<think>` blocks are removed from the answer.
- The summary is proposed as scope and content.

**Condition:**

- A vision model reads the picture through `ollama/api/chat` with `format: json`. It is asked for a grade from the `condition-grade` vocabulary and the damage it sees.
- The answer is proposed as the instantiation's `instantiation-physical-characteristics` (ISAD(G) 3.4.4 and RiC-O `rico:physicalCharacteristics`). A `value` proposal that names an instantiation is now written to the instantiation when accepted.

**Tests.** `tests/Feature/AiAssistTest.php`:

- names found in the description and the transcript, proposed with their passage and spaCy's score only where given, repeated and unknown kinds left out, accepted as a place;
- a summary proposed as scope and content without the model's thinking, with the institution's chosen model;
- a condition proposed from the picture with the vocabulary's grades and written to the instantiation;
- nothing asked while AI is off, or by an account that may not update the description.

## 17b2: resolving names to authority records and places

All of Heratio's `ahg-authority-resolution` comes across, adapted to RiC.

**Mentions.** Every proposed agent or place is a mention: one a model found as soon as it is found, with spaCy's exact offsets and score, and one sent through the API when it is brought into the queue (`workspace:authority:promote`). `MentionContext`, copied from Heratio's ContextDerivationService, gives it:

- its passage, 150 characters each side, in the text names are found in;
- the dates, places and other names of the unit's proposals in the same paragraph;
- the institution's words of role within 120 characters. These are Heratio's English list with Afrikaans added, kept per institution.

**Candidates** are the institution's own agents of the right type, or its places, whose names hold every word of the name. Heratio's name similarity is taken on the names as written and on their words in order, so inverted names score 1. Five are kept unless the institution sets another number.

**Evidence.** Heratio's ten evaluators are copied, reading their facts from the RiC description through `Evidence\Facts`:

- an agent's dates (its RiC dates, else ISAAR(CPF) 5.2.1);
- the places and agents it has RiC relations with, and its ISAAR(CPF) places;
- its history, functions, mandates and legal status;
- a place's broader places;
- the places a fonds uses, from its RiC place relations (`DocumentPrior`).

A place's own dates may contradict it, where Heratio's place conflict check is a stub. The scorer keeps Heratio's weights: a match +0.10, a conflict -0.30, clamped to 0 to 1.

**Decisions** link, link to another record, make a new one, park or reject.

- Linking and making go through the review queue's accept, so the mention and its proposal never disagree.
- Accepting a proposal a model made now records the relation's first assertion as the model's, for the account it proposed for, and the reviewer reviews that assertion. The Inferred-Provenance export then says it was generated by the model.
- Each decision freezes every candidate and its evidence in `authority_decisions`, which is append-only.
- A rejection keeps the name as NER feedback, exported as JSON Lines or CoNLL.

**New records.**

- **Filling.** A new record is filled by `Prefill`, copied from Heratio's PrefillEngine, from the outside authorities the institution has switched on, in its order. Each field's source, address, licence and retrieval time is kept in `authority_field_sources`, and an agent's in its ISAAR(CPF) sources.
- **Sources.** VIAF and Wikidata (CC0) and GeoNames (CC BY 4.0, with the institution's username, over HTTPS) are searched; TGN, GND, ISNI and SAGNC are listed as Heratio's stubs and cannot be switched on.
- **Rate limits** are Laravel's rate limiter, so they hold across workers.

**The park queue** keeps a reason and the candidates' fingerprint. It is scanned nightly at 02:40, and a parked name is flagged when a new candidate appears.

**Assignment** keeps the archivist on the mention and tells them by mail, which is Heratio's path without its workflow. The Workspace's workflows do not move mentions.

**Tests.** `tests/Feature/AuthorityResolutionTest.php`:

- a mention's context, and the right Maria Botha ranked first by dates, places and words of role, the older one contradicting the letter's year;
- linking that accepts the proposal and keeps the frozen decision, a model's name keeping the model's reviewed assertion;
- a parked name flagged when a new candidate appears, and taken up again;
- a rejected name kept and exported in CoNLL;
- a new place filled from GeoNames, with its field sources, and an incomplete authority record refused;
- a name given to a colleague, with mail;
- the settings for administrators only, with the stubs never switched on.

`tests/Feature/AccessMatrixTest.php` builds a mention and its candidate.

## 17c: translation

Heratio's `ahg-translation` comes across in two parts.

**Descriptions.** **Translate with AI** sends the chosen ISAD(G) text elements of a unit, each in the language chosen, to the gateway's NLLB-200 route (`/ai/v1/translate`, `{text, source, target}`) in the background.

- **Each translation** is proposed as the element in the target language, naming its receipt.
- **Accepting it** writes the element as a translation, and the original stays.
- **What is left alone.** An element already in the target language, or the same translation already waiting, is not sent.
- **Exact language.** `Describable::describedIn()` gives an element in exactly one language, where `described()` falls back to another. The translate service uses it.
- **Choices.** The targets are Heratio's 21 languages, shown by their own names. The institution's default target and elements sit on the AI page.
- **What it replaces.** Heratio's drafts, their dedupe and their log are the review queue and the log of inferences.

**The interface.** `OverlayLoader`, copied from Heratio's DbAwareLoader, lays an institution's own wording over the JSON translations, for its staff and its public catalogue only.

- `InterfaceStrings`, after UiStringService, lists every string (the keys of `lang/af.json`) in each interface language, as shipped and as reworded.
- A wording is proposed by whoever may translate and accepted or rejected by whoever administers the institution, who may also set it at once. An empty wording goes back to the shipped one.
- Every change is kept, with its status from the assertion-status vocabulary.
- The gateway suggests a translation of a string on request.

**Tests.** `tests/Feature/TranslationTest.php`:

- the chosen elements translated one by one, an element already in the target language left, a corrected translation accepted beside the original, and an unknown target refused;
- an interface wording proposed by a translator and accepted by an administrator, seen by that institution only, kept in the history, and put back when emptied;
- a machine suggestion for a string.

## 17d: all of ahg-semantic-search

On 5 November 2026 Dr Johan Pieterse chose to take all of `ahg-semantic-search`, 30,000 lines, in phase 17, split into four releases:

- 17d1: search by meaning;
- 17d2: repatriation;
- 17d3: displaced and endangered heritage;
- 17d4: language corpus, scholarship and research leads.

He creates pgvector himself in the dev, test and live databases. The migration only checks that it is there, and CI uses the `pgvector/pgvector:0.6.0-pg16` image.

### 17d1: search by meaning, widening, reranking, related records, grounding and ways in

**Vectors.**

- **What is embedded.** Every entry of the search index, public and staff, is embedded through the gateway's Ollama embed route (`nomic-embed-text`, 768 dimensions, in batches of 32).
- **Where it is kept.** The vectors are in `search_vectors` with an HNSW cosine index, re-embedded when the hashed text or the model changes, by `workspace:search:embed` every ten minutes while an institution searches by meaning.
- **Receipts.** Every batch and every query is a receipt.

**Search.** `SearchService::search()` takes a mode and an account.

- **Words** is the full-text search. The query and each widening term are OR'd as separate tsqueries, since `websearch_to_tsquery` would AND appended words.
- **Meaning** is the 200 nearest vectors at least as alike as the institution's floor (`search_settings.min_similarity`, 0.40 by default), under the same filters in the same SQL. Without a floor the nearest entries would always be "found", however unrelated.
- **Both** fuses the two lists by reciprocal rank (k = 60).
- **The rest.** Facets are counted over what matched. A page may be reranked by the account's recent searches (Heratio's HistoryRerankService). Those searches are kept in `search_history` only while reranking is on, and only the last 25, because the search statistics of phase 6 deliberately keep no one's name.

**Widening.** A word of the query that names a concept by a preferred or alternative label brings in the concept's other labels. Only descriptive vocabularies (those with a kind) count, never the operational lists. A language model may add terms, with Heratio's prompt and parser. Five terms at most unless the institution sets another number.

**Related records** are a unit's nearest entries for the same audience. On the staff page they are filtered by the reader's permissions.

**Grounding.** `/ground` (the Graph-Grounding profile) finds public entities by the public search and builds Heratio's pack from each one's public JSON-LD, with Heratio's grounding text.

**Ways in.** `/discover/ways-in` counts themes, places, people and genres from the RiC relations, and the timeline from the units' dates. It counts over the public search index only.

**Tests.** `tests/Feature/SearchByMeaningTest.php`:

- a fake embedding model where words of one meaning share a dimension;
- found by meaning where not by the words, and both fused;
- embedding again only on change;
- widening by a concept's labels, and switching it off;
- reranking by recent searches;
- related records and grounding;
- the ways in counting only what is published;
- the settings.

### 17d2: repatriation claims, dialogue, shared records and virtual return

Repatriation is not AI. It is here because it is part of `ahg-semantic-search`.

**A claim is a request, not a verdict.** Every page that shows claims carries Heratio's disclaimer. The statuses (registered, under review, acknowledged, virtual return, returned, disputed) are a vocabulary of neutral stages. Every change is kept in `repatriation_status_changes`, the first included, with who made it and the note.

**Claims belong to a description.** Heratio's soft `item_ref` becomes a foreign key to the record resource, in the claim's institution. The claimant may also be an authority record, and the place of origin a place, beside their names as given.

**Who may.** A new permission, **Manage repatriation claims** (`manage-repatriation`, on the institution), granted to administrators. Heratio gave it to administrators only.

**Links to the shared record.** A claimant's representative reads the claim and writes in the dialogue through a link, without an account. The token is 48 random characters, shown once; only its SHA-256 is kept, as for the share links of 19a. Heratio kept the token. An observer's link never writes.

**Public pages are off until chosen.** `repatriation_settings` holds three choices:

- `public_register`: the dashboard, its JSON and each claim's virtual return. Heratio showed them always. A claim about an unpublished description is counted but never shown.
- `public_lodging`: anyone may lodge a claim about a published description. Heratio allowed it only for an object its displaced-heritage register traces. That register is 17d3, and it finds few objects (it needs a recognisable origin and holding country), while a community often knows its object when the catalogue does not. The claim arrives as registered and is reviewed like any other. A hidden field, three seconds and five a minute keep robots out, as in Heratio.
- `notify`: those who manage claims are mailed of a new claim and each change of status (Heratio told administrators in the application); the claimant is mailed at the claim's own email field (Heratio searched the contact text for one). Mail to someone without an account goes through `Outbox::address`, past the same suppression gate.

**Community knowledge** is pending until reviewed, and the contributor's name is kept only with consent (a check constraint).

**Not yet.** The 3D walk of a virtual return waits for the exhibitions of phase 21. The displaced-heritage register that prefills a claim is 17d3.

**Tests.** `tests/Feature/RepatriationTest.php`:

- registering and moving a claim on, with its history and the mail;
- the dialogue and the shared record by its link, until revoked;
- the public register and virtual returns, with knowledge shown once approved;
- public lodging only where allowed, with the robot guards.

### 17d3: heritage at risk and displaced heritage

Neither is AI. Both are here because they are part of `ahg-semantic-search`.

**The displaced-heritage register** is Heratio's heuristic, copied with its table of countries, peoples and cultures, its whole-word matching (longest first) and its rule: flag only where both sides are known and differ. Heratio reads its museum fields, which the Workspace has only from phase 21. Until then:

- **Origin** is the location of the earliest owner in the description's provenance chain, which is already RiC data.
- **Holding** is the country in the repository's contact details, else the latest owner's location.

The register keeps nothing; it is read each time, as in Heratio. It prefills a repatriation claim (17d2) with the origin it traced.

**Heritage at risk.**

- **A flag.** `endangered_items` holds one flag a description: risk, urgency and capture status as vocabularies, and a reason.
- **The score.** Heratio's weights (1000, 100, 10, 1, and 5 for a digitisation gap) are kept in code by notation.
- **Who may.** Whoever may change the description may flag it. Heratio limited flagging to administrators, but deciding what is fragile is cataloguing work.

**The network.**

- **Reading.** The published register answers peers signed with the installation's key, at `/federation/endangered` and at Heratio's `/api/v1/endangered`, so a Heratio reads a Workspace unchanged. Codes travel in Heratio's form (`digitisation_gap`, `in_progress`).
- **Pushing.** A peer pushes to `/federation/endangered/inbound` or `/api/v1/endangered/inbound`. It must be an active peer of the institution, not untrusted, and allowed the new `endangered` surface. Its signature is checked against the pinned key and required where the institution or the peer's trust requires it. The push is kept in `endangered_inbound_flags` until a curator accepts it.
- **The board.** It merges the institution's published register, each peer's register (in parallel, public addresses only) and the accepted pushes.

**Off until chosen.** `endangered_settings` holds `public_register`, `share_with_peers` and `accept_pushes`. Heratio showed its register and answered peers always. The public displaced-heritage register follows the repatriation register's switch (17d2).

**Tests.** `tests/Feature/HeritageAtRiskTest.php`:

- tracing, and the prefill;
- flags, the worklist and the public register;
- the signed answer, a peer's register on the board, and a push from receipt to acceptance.
