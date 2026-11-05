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
