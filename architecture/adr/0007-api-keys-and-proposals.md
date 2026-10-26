# ADR 0007: API keys, and proposals an archivist reviews

- Status: accepted
- Date: 2026-10-26
- Deciders: Dr Johan Pieterse
- Gate: G2

## Context

The AHG Archives MCP (ArchiveHeritageGroup/ahg-archives-mcp) lets AI assistants search and read a catalogue with a citable link for every answer, and lets staff propose improvements that an archivist reviews. It reads the Workspace's open API today, read-only. For the rest of what it does it needs four things the Workspace lacks: API keys tied to an account, an endpoint to post proposals to, a review queue, and a list of a caller's proposals. Its contract is the one Heratio implemented in v1.160.0 to v1.163.3 (heratio#1558): keys with scopes, an end date, hourly and daily limits, last use and revocation; proposals of names, places and dates with evidence and sources, of field text in a language, of relationships between agents, and of transcriptions; answers of queued, already proposed, 403, 429 with Retry-After and 501.

The roadmap had put integration protocols and AI assistance at V2.0 (WS-018, WS-019). On 26 October 2026 Dr Johan Pieterse decided to bring this work forward, to copy Heratio's implementation under the provenance ledger, to hold proposed relationships as assertions, and to include file text and images.

## Options

1. A separate proposals table for everything, as Heratio has (`ahg_ai_suggestion`, `ahg_ner_entity`, relationship proposals). Copies Heratio closely, but puts a second, unreviewed copy of relations beside the relations table, outside WS-006.
2. Proposed relationships as relation assertions with the status Proposed, and a proposals table only for what has no assertion model yet (names, places, dates, values, transcriptions). Uses what WS-006 already built: who asserted, from what source, on what basis, with what review status; a rejected assertion is kept.

## Decision

1. **Keys** (implemented first, v0.0.46). A key belongs to one account of one institution and works only on that institution's host. Only its SHA-256 and first eight characters are kept; the key is shown once. Its scopes are concepts of the `api-scope` vocabulary: read, and write (propose changes). An account may give its keys only the scopes it qualifies for (`workspace.api_scope_requires`: write needs create or update on descriptions or authority records), and a key's scopes are cut to what its account holds at each request, so a key never does more than its account. API reads see what the Public role sees, with or without a key: the MCP's service key must not see more than a visitor. A key has an hourly limit (1,000 unless set) and an optional daily limit; past either the API answers 429 with Retry-After. A revoked key keeps its row. An account manages its own keys; an administrator (manage accounts) manages those of another account of the institution. Issuing, the scopes given and revoking are in the audit log.
2. **Proposals** (next). `POST /api/ric/v1/records/{id}/proposals` with a write key, answered queued, already proposed, 403 without the right to propose on that record, 429 at the limits, 501 for a kind of proposal not supported. A proposed relationship becomes a relation assertion with status Proposed and origin Model, its evidence and sources attached (option 2). Names, places, dates, values and transcriptions are held in a proposals table until reviewed.
3. **Review queue**. Reviewers (the `review` permission on descriptions) approve, edit or reject each proposal; approval writes through the existing services, so a value written is audited and goes through publication as any other. Nothing reaches a record without that review.
4. **The caller's proposals**: `GET /api/ric/v1/proposals?mine=1|0`; mine=0 only for administrators and editors.
5. **File text and images**: the text of a file (OCR or transcription) and its images through the API, under the same file access rules as the public catalogue.
6. **Heratio code reused** under the provenance ledger, from heratio-dev at `5100c0f92`: `ApiKeyService`, `ApiAuthenticate`, `ApiRateLimit` and `KeyPageController` of `ahg-api` (ported), and `ProposalController` (to port with proposals). The review screens of `ahg-ai-services` are followed for behaviour only: their data access is AtoM's schema (prohibited-copy item 3).

## Evidence

`tests/Feature/ApiKeyTest.php`: scopes no wider than the account's, and cut when it loses a role; only a fingerprint kept and none in the audit log; keyed reads equal to anonymous ones; unknown, revoked, expired and disabled-account keys refused with 401; a key refused on another institution's host; the daily and hourly limits answering 429 with Retry-After; the key pages, including that one account cannot revoke another's key.

## Consequences

The MCP can identify its callers and limit them before proposals exist; reads are unchanged for everyone. Proposals arrive as data a person must review, never as changes. Keys are counted per hour in `api_key_usage`, which grows by at most one row per key and hour; pruning old rows is left until it matters. If the Workspace ever lets a key read drafts, this ADR is the place that must change, because the MCP's leak test relies on keys reading as the public.
