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

## Next actions

1. Name the product, archival domain, standards, technical, legal and provenance, and security leads; recruit one pilot institution (G0).
2. Freeze the V0.1 demonstration scenario and sample dataset, including one relationship whose evidence can change.
3. Run the Heratio source audit and the relational, rights and provenance spikes, each recorded as an ADR (G1).
4. Estimate the V0.1 work by role and dependency after the spikes; publish only conditional targets.
