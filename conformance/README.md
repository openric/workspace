# Conformance matrix

The OpenRiC profiles the Workspace will implement, pinned to **OpenRiC specification v0.48.0** (tag commit `39cc48a9`, the S-GATE baseline). Profile identities, status and versions are copied from that release's `_data/profiles.json`, not typed by hand; when the pinned specification changes, this table is regenerated and the change is recorded here.

No implementation exists yet, so nothing is claimed. Each row will link a profile requirement to its issue, its automated test and the conformance report of each build. There is no blanket "OpenRiC compliant" claim without a scope.

| Profile | Status in v0.48.0 | Profile version | Proposed release (not yet decided) | Implementation | Last conformance report |
|---|---|---|---|---|---|
| `core-discovery` | normative | 0.4.0 | V0.1 (WS-009, WS-010) | not started | - |
| `authority-context` | normative | 0.4.0 | V0.1 (WS-003) | not started | - |
| `graph-traversal` | normative | 0.5.0 | V0.1 (WS-009) | not started | - |
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
