# ADR 0003: Implementation starts before the S-GATE

- Status: accepted
- Date: 2026-10-19
- Deciders: Dr Johan Pieterse
- Gate: S, G2

## Context

The roadmap held that implementation against normative OpenRiC contracts waits for the Standard Acceptance Gate (S-GATE): a versioned specification, independent fixtures and formal acceptance after an external review. The external reviewer is not appointed (OpenRiC known issue K-1), so the S-GATE has no date, and waiting for it would leave the Workspace without code indefinitely.

## Options

1. **Wait for the S-GATE.** Keeps the Workspace from defining the standard by accident, at the cost of no application until an external review happens.
2. **Build only what no OpenRiC contract defines now** (install, accounts, vocabularies, the hierarchy, relations, audit) and leave export and the API profiles for the S-GATE.
3. **Start all of it now**, including the OpenRiC export and API profiles, and manage the risk instead of avoiding it.

## Decision

Option 3, decided by Dr Johan Pieterse on 19 October 2026. The S-GATE stays a gate for the standard itself, but no longer holds back the Workspace.

The risk option 1 guarded against is real: an implementation built by the same people as the specification can turn its own choices into the standard. Four rules keep that from happening:

1. The Workspace implements a pinned, released specification version (`conformance/README.md`), never the main branch.
2. Where the Workspace needs something the specification does not define, the gap goes to openric/spec as an issue first. The Workspace does not invent a term, an endpoint or a field and leave it for the specification to adopt.
3. Every build passes the probe and the term check in `conformance/README.md`, so the Workspace cannot drift from what it claims.
4. The Workspace never counts as the independent implementation the OpenRiC v1.0 gate requires (K-6), because it comes from the same organisation.

## Evidence

The roadmap's gate table and OpenRiC known issues K-1 and K-6, as of 19 October 2026.

## Consequences

Work on V0.1 can begin at once. If the S-GATE review changes a profile the Workspace has already implemented, the Workspace follows the specification, not the other way round; the cost is rework, recorded against the pinned version it was built for. `ROADMAP.md` is updated to match.
