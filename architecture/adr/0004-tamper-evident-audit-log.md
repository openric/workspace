# ADR 0004: A tamper-evident audit log, reusing Heratio's hash chain

- Status: accepted
- Date: 2026-10-19
- Deciders: Dr Johan Pieterse
- Gate: G1

## Context

The audit log is append-only: PostgreSQL refuses to change, delete or truncate a row (migration `2026_10_19_000200`). That stops the application, but not someone with database access who switches the trigger off, edits a row and switches it back on. WS-006 and WS-011 need history that can be shown to be complete and unaltered, not only history the application cannot alter.

Heratio already solves this. `ahg-audit-trail` hash-chains every audit row and signs it with Ed25519, and a verify command walks the chain; the canonical JSON (RFC 8785) and the signing come from `ahg-inference-receipts`.

## Options

1. **Keep the append-only trigger only.** Simple, but a change made around the application leaves no trace.
2. **Write a hash chain from scratch.** Possible, but the canonicalisation and signing are easy to get subtly wrong, and Heratio's are tested.
3. **Reuse Heratio's chain under the provenance ledger.** Copy the three cryptographic classes as they are, and port the writer and verifier to the Workspace's schema.

## Decision

Option 3. Copied with no change but the namespace: `JcsEncoder`, `KeyPair` and `Signer` from `ahg-inference-receipts`, with their tests (Apache-2.0; licence and notice in `governance/licences/`). Ported, keeping their behaviour and rewriting their data access: `ChainedAuditWriter` as `App\Audit\AuditChain`, and `VerifyChainCommand` as `workspace:audit:verify` (AGPL-3.0-or-later). Every file has a row in `governance/CODE_PROVENANCE.md`.

The G1 audit puts `ahg-inference-receipts` out of scope for V0.1 as an AI package (prohibited-copy rule 5). This decision brings in those three classes only: they are generic canonical JSON and Ed25519 code with no dependency on the rest of Heratio or on any AI service. `Receipt` and `ReceiptChain` are not copied; the three constants the chain needs are defined in `AuditChain`.

Two behaviours change from Heratio, deliberately:

1. **Fail closed.** Heratio writes an unsigned row when the key is missing or the chained insert fails, so the caller never breaks. The Workspace writes nothing, and the change being audited rolls back with the audit row: a change is never stored without an intact record.
2. **The Workspace's own key.** A key created by `workspace:audit:create-key`, kept in `storage/keys/` outside git and the database, never Heratio's (prohibited-copy rule 4). Public keys are kept in `audit_signing_keys`, which refuses deletion, so a retired key still verifies old rows.

Rows written before the chain existed cannot be chained afterwards, because the log is append-only. They stay as unchained history, reported by the verifier, and a `NOT VALID` check constraint makes every new row chained.

## Evidence

Heratio commit `a060339c592bfb4d903679899c04bd56c5fe5744`; the copied files are unchanged since the G1 audit commit `74239a1`. Tests: the copied crypto tests, and `tests/Feature/AuditChainTest.php` (continuity, an intact chain verifies, an edited row and a removed row are detected, no key means no change, the database refuses an unchained row).

## Consequences

Every change now depends on the signing key: if it is lost or unreadable, the Workspace refuses all changes until it is restored. The key is backed up with the database, but separately from it, since a database dump does not contain it. Writers queue on a PostgreSQL advisory lock around the chain head, which is fine at an archive's write rate and would need revisiting only under heavy concurrent writing. Revisit if Heratio's chain format changes, so a fix there can be ported.
