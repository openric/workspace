# ADR 0013: The Portability and Governance profiles: RiC-O import, deprecated IRIs, the dataset and the SHACL gate

- Status: accepted
- Date: 2026-10-30
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 11b adds RiC-O import with a dry run, and the two OpenRiC profiles the Workspace had not declared: Portability 0.43.0 (round trip, a DCAT and VoID dataset descriptor, a change feed) and Governance (pinned standards, stable IRIs, deprecate rather than delete, SHACL as the gate, a versioned change process). Heratio implements both in `ahg-ric` (at `492745e31`): `RdfImportService`, `RicDeprecationService`, `RicDatasetService` and `RicRoundTripTest`.

Governance 0.44.0 required Heratio's own IRI forms: internal `urn:ahg:ric:<type>:<id>` graph IRIs and public `https://<host>/ric/<type>/<slug>`. The Workspace mints `{base IRI}/{segment}/{UUID}`, and keeps no graph apart from its database. On 30 October 2026 Dr Johan Pieterse chose to amend the specification first: Governance 0.45.0 (spec v0.56.0) states the rule (minted once from a stable identifier under one configured base, never from a label, the same in every environment) and gives Heratio's forms as an example. The internal layer is required only of a server that keeps a separate graph.

## Options

1. Port Heratio's four classes as they are.
2. Port the deprecation register and the dataset descriptor, and write the import against the Workspace's own export.

## Decision

Option 2.

- **RiC-O import.** `RicReader` reads JSON-LD, Turtle or RDF/XML with EasyRdf, the library that already writes the Workspace's Turtle and RDF/XML. Heratio's parser for Turtle and RDF/XML (about 300 of its 749 lines) is not copied, and nor is its mapping: it reads title, identifier and description into AtoM's tables. The reader is the reverse of `App\Api\JsonLd` and reads every term JsonLd writes.
  - One import file can hold units, authority records and repositories. Each entry carries its table, and the import's dispatch, keys and reconciliation follow it.
  - A node that only names something described elsewhere is a reference, found by its IRI. Every property and class no entry reads is listed as left out.
  - A round trip writes nothing. That needs three readings:
    - A reference code, or a unit's id, given as `rico:identifier` is not a new identifier.
    - `rico:hasCreator` is met by an accumulator already linked.
    - A standardised form of name, written as `openricx:alternativeForm` like a parallel one, is not a new parallel name.
- **Deprecation register.** It is after `RicDeprecationService`, as the table `deprecations`. An entry is written when an entity is deleted (the delete form takes a reason and the successor's persistent IRI) or merged (the successor is the record kept). It keeps the entity's class and published name as they were, and whether the public could read it.
  - The Workspace keeps a deleted entity's row but removes it from every view, so this is the only place that knows what the public saw.
  - An entity the public could read answers, in the API, its export and the public catalogue, **410 Gone** with `owl:deprecated`, `dcterms:isReplacedBy` and the reason as `rdfs:comment`.
  - We chose 410 over 200 so a client that validates each 200 against the record schema does not fail, and over 404 because the IRI is not missing.
  - An entity the public never saw stays 404, so a draft's IRI leaks nothing.
  - Rows deleted or merged before the register were added by its migration as not known to have been public.
- **Dataset and change feed.** `/dataset` is after `RicDatasetService`, but follows the specification's `dataset.schema.json`, which Heratio's does not: one `@type`, and access URLs as URIs. A URI template is not a URI, so each record's export is reached from the records list.
  - No SPARQL endpoint is named, as there is none. No licence is claimed, as an institution sets none.
  - `/changelog` reads `resources/linked-data-changes.json`, kept with each release that changes the published Linked Data.
- **Governance pin.** `conformance/governance-pin.md` lists the pinned standards: RiC-O 1.1, SKOS, the extension ontology 1.6.0 and OpenRiC 0.56.0. PROV-O and CIDOC-CRM are not pinned, as the Workspace does not publish them.
- **SHACL gate.** `ShaclGateTest` checks every kind of published RiC-O (a record set, a record, the export graph, a corporate body, a person, a repository and a deprecated entity) against the pinned shapes with pySHACL. CI installs pySHACL and fails if it is missing. A warning (`sh:Warning`) is advice; a violation fails the build.

## Evidence

- `tests/Feature/PortabilityTest.php`:
  - A record with its holder and two creators exported as JSON-LD, Turtle and RDF/XML reads back unchanged, with no audit row, entity, relation or name added.
  - The same graph under another IRI makes a new record with its titles, description, extent, identifier, level, dates, holder and creators.
  - What has no place is listed, and the dataset descriptor validates against the schema.
- `tests/Feature/GovernanceTest.php`: 410 with reason and successor for a deleted record and a merged authority record, in JSON-LD, Turtle and the public catalogue; 404 for a draft; a successor of another kind refused.
- `tests/Feature/ShaclGateTest.php`.

## Consequences

- The gate found a fault on its first run: a unit named as the other end of a property was written with `rico:name`, and the shapes require `rico:title` of a `rico:Record`. Stubs and tombstones of units now carry `rico:title`, which the change feed records.
- Rights, custody steps, subjects, places and documentary forms in a RiC-O file are listed, not read: the import takes what the export's Core Discovery and Export-Only terms carry.
- Validate-on-export (Portability 5, optional) is not offered: it would need pySHACL on the server for every request.
