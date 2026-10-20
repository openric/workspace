# Conformance matrix

The OpenRiC profiles the Workspace will implement, pinned to **OpenRiC specification v0.53.0** (tag commit `9a492e2b`, 19 October 2026; repinned from v0.48.0, the S-GATE baseline). The same version is `openric_spec_version` in `config/workspace.php`; the two change together. Profile identities, status and versions are copied from that release's `_data/profiles.json`, not typed by hand; when the pinned specification changes, this table is regenerated and the change is recorded here.

The Workspace declares one profile, Core Discovery, in its service description (`GET /api/ric/v1/`), from v0.0.23. Every other profile is not claimed, and its endpoints are not served. Each row links a profile to its tests and its last conformance report. There is no blanket "OpenRiC compliant" claim without a scope.

| Profile | Status in v0.53.0 | Profile version | Proposed release (not yet decided) | Implementation | Last conformance report |
|---|---|---|---|---|---|
| `core-discovery` | normative | 0.6.0 | V0.1 (WS-009, WS-010) | v0.0.23: `app/Http/Controllers/Api`, `app/Api/JsonLd.php`; tests `tests/Feature/ApiTest.php` (JSON Schemas of spec 0.53.0, term check) | 20 October 2026, a rehearsal of live: probe 11 of 11 pass, verdict pass; SHACL `core-discovery.shacl.ttl` and `openric.shacl.ttl` conform |
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

Profiles are the same in v0.52.1 and v0.53.0; v0.53.0 adds the role-type vocabulary and extension ontology 1.4.0.


Two profile versions moved: `core-discovery` 0.4.0 to 0.6.0, and `graph-traversal` 0.5.0 to 0.6.0. Graph Traversal 0.6.0 requires every graph node that stands for an entity the API serves to carry that entity's API `@id` (Graph Primitives invariant 7), so a node can be followed to its description and matches the SPARQL graph. The extension ontology the Workspace emits against is now 1.4.0 (`https://openric.org/ns/ext/v1.4.0`).

## Checks every build must pass

These hold whatever source a piece of code came from, so a fix made in the OpenRiC reference service cannot be lost by copying older code:

1. The conformance probe for each declared profile, including its Graph Primitives checks: every node type is a RiC-O 1.1 or OpenRiC extension class (invariant 5), and graph nodes carry the API `@id` and dereference to it (invariant 7).
2. A term check on the build's RDF output: every term in the RiC-O namespace is defined by RiC-O 1.1, and every `openricx:` term is defined by the published extension ontology at `https://openric.org/ns/ext/v1.ttl`. The reference service runs the same check before each nightly rebuild of its SPARQL graph (`openric:rebuild-graph`, service v0.17.25).

## Where the Workspace departs from the specification, and why

Found while implementing Core Discovery; each is reported to the specification's editor.

- **Autocomplete shape.** The profile (3.5) gives `{"query", "items": [{"@id", "@type", "label", "score"}]}`; `autocomplete.schema.json` a top-level array of `{id, label, type}`; the probe accepts an array or `results`. The Workspace sends the array the schema and probe accept, each item also carrying `@id`, `@type` and `score`.
- **Autocomplete minimum.** The profile enforces a two-character minimum; the probe asks with one character. The Workspace answers a shorter query with an empty list, not an error, so both hold.
- **Vocabulary key.** The profile (3.2) names the property list `properties`, the schema `predicates`. The Workspace sends both.
- **Extent.** The mapping (7.1) and `record.schema.json` put the extent text as a literal on `rico:hasExtentType`, an object property in RiC-O 1.1 (Extent to ExtentType). The Workspace sends the extent as its own `rico:RecordResourceExtent` node with an IRI, the text on `rico:textualValue`, which the schema accepts as a reference.
