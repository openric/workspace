# Roadmap

From the OpenRiC Workspace specification and delivery plan of 18 October 2026 (direction accepted; owners, standard acceptance, source reuse clearance, database choice and estimates open). No calendar dates are given until staffing, the S-GATE and a pilot institution are confirmed.

## Releases

| Release | Purpose | Not claimed |
|---|---|---|
| V0.1, proof of concept | Archivists and reviewers validate one end-to-end workflow on representative test data, on an independently deployed instance. | Production service, complete archival lifecycle, certification. |
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

## What may happen before the S-GATE

Preparatory research, workflow modelling, UX prototyping, the Heratio source audit (G1) and the database and data-model spikes may run now, as long as they are marked non-normative and do not become de facto definitions of the standard. Implementation against normative OpenRiC contracts waits for the S-GATE.

## Next actions

1. Name the product, archival domain, standards, technical, legal and provenance, and security leads; recruit one pilot institution (G0).
2. Freeze the V0.1 demonstration scenario and sample dataset, including one relationship whose evidence can change.
3. Run the Heratio source audit and the relational, rights and provenance spikes, each recorded as an ADR (G1).
4. Estimate the V0.1 work by role and dependency after the spikes; publish only conditional targets.
