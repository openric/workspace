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

