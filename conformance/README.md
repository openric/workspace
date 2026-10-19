# Conformance matrix

The OpenRiC profiles the Workspace will implement, pinned to **OpenRiC specification v0.52.1** (tag commit `a036bfff`, 19 October 2026; repinned from v0.48.0, the S-GATE baseline). Profile identities, status and versions are copied from that release's `_data/profiles.json`, not typed by hand; when the pinned specification changes, this table is regenerated and the change is recorded here.

No implementation exists yet, so nothing is claimed. Each row will link a profile requirement to its issue, its automated test and the conformance report of each build. There is no blanket "OpenRiC compliant" claim without a scope.

| Profile | Status in v0.52.1 | Profile version | Proposed release (not yet decided) | Implementation | Last conformance report |
|---|---|---|---|---|---|
| `core-discovery` | normative | 0.6.0 | V0.1 (WS-009, WS-010) | not started | - |
| `authority-context` | normative | 0.4.0 | V0.1 (WS-003) | not started | - |
| `graph-traversal` | normative | 0.6.0 | V0.1 (WS-009) | not started | - |
| `digital-object-linkage` | normative | 0.6.0 | V0.1 (WS-007) | not started | - |
| `round-trip-editing` | normative | 0.7.0 | V1.0 | not started | - |
| `provenance-event` | normative | 0.8.0 | V0.1 (WS-006) | not started | - |
| `export-only` | normative | 0.9.0 | V0.1 (WS-010) | not started | - |
| `sparql-access` | draft | 0.1.0 | Optional; not V0.1 (no triplestore required) | not started | - |
| `governance` | draft | 0.44.0 | Not planned; draft profile, review at G6 | not started | - |
| `portability` | draft | 0.43.0 | Not planned; draft profile, review at G6 | not started | - |
| `inferred-provenance` | draft | 0.43.0 | Not planned; draft profile, review at G6 | not started | - |
| `graph-grounding` | draft | 0.43.0 | Not planned; draft profile, review at G6 | not started | - |

Test method: the OpenRiC conformance probe (`conformance/probe.sh` in openric/spec) run against a Workspace build, together with the specification's JSON Schemas, SHACL shapes and fixtures, including the independent ones once published.

## Changes from the v0.48.0 pin

Two profile versions moved: `core-discovery` 0.4.0 to 0.6.0, and `graph-traversal` 0.5.0 to 0.6.0. Graph Traversal 0.6.0 requires every graph node that stands for an entity the API serves to carry that entity's API `@id` (Graph Primitives invariant 7), so a node can be followed to its description and matches the SPARQL graph. The extension ontology the Workspace emits against is now 1.3.1 (`https://openric.org/ns/ext/v1.3.1`).

## Checks every build must pass

These hold whatever source a piece of code came from, so a fix made in the OpenRiC reference service cannot be lost by copying older code:

1. The conformance probe for each declared profile, including its Graph Primitives checks: every node type is a RiC-O 1.1 or OpenRiC extension class (invariant 5), and graph nodes carry the API `@id` and dereference to it (invariant 7).
2. A term check on the build's RDF output: every term in the RiC-O namespace is defined by RiC-O 1.1, and every `openricx:` term is defined by the published extension ontology at `https://openric.org/ns/ext/v1.ttl`. The reference service runs the same check before each nightly rebuild of its SPARQL graph (`openric:rebuild-graph`, service v0.17.25).

