# ADR 0018: Every relation in RiC-O, rules and mandates, and exchange without silent loss

- Status: accepted
- Date: 2026-11-02
- Deciders: Dr Johan Pieterse
- Gate: G2, G5

## Context

Three external reviews of 1 and 2 November 2026 (a security and functional audit, a RiC standards gap assessment and a revised design specification) warned against a conventional catalogue whose RDF export hides lost context. The access matrix of phase 23a then showed it was true of the Workspace: the RiC-O export carried a record's creators, holder, hierarchy, rights and custody, and nothing of the relations the Workspace keeps between agents, activities, places, positions and records. The relation-type vocabulary said, in words, how each type is exported; no code read it. Mandates were text on an agent; instantiations could not be derived from one another; a lost carrier had no status. Dr Johan Pieterse chose on 2 November 2026 to export relations as the OpenRiC specification says they leave a server: by its profiles, not by widening the record shape that Core Discovery limits.

## Decision

- **The mapping is data.** Each bundled relation type keeps its RiC-O class, the property from its source and the inverse (`relation_types.ric_class`, `ric_property`, `ric_inverse`), taken from the scope notes and checked against RiC-O 1.1 and the extension ontology 1.6.0. An institution's own type takes its base's. The export and the import read the same columns.
- **Two profiles are declared**, Authority & Context 0.4.0 and Graph Traversal 0.6.0, each to its pinned schemas and shapes (`conformance/README.md`). Every entity's JSON-LD carries each relation the reader may follow by its property, the other end as its IRI only; a relation with dates, certainty, evidence or an agent role also travels as its own `rico:Relation` node (mapping section 8), from its source, by `rico:thingIsSourceOfRelation`. A disputed relation says so (`rico:relationCertainty "disputed"`) rather than read as a fact. Digital Object Linkage is not declared: it requires `/functions`, which the Workspace does not keep; instantiations stay out of the JSON-LD until it is.
- **Rules and mandates are entities** (`rules`, `rico:Rule`; `rico:Mandate` when the rule type is mandate, typed both so the Rule shape applies), after Heratio's `ric_rule` and the Workspace's activity. A mandate gives an agent its authority (`rico:MandateRelation`); a rule regulates a unit, an activity, an agent or an instantiation (`rico:regulatesOrRegulated`).
- **New relation types**: derivation between instantiations (`rico:DerivationRelation`), mandate, regulation, and premises, a repository at a place over time (`rico:hasOrHadLocation`). **New values**: a lost carrier (file availability `lost`) and a place's location in words (RiC-CM RiC-A27, `rico:location`).
- **Places, repositories and names are exported whole**: every name with the dates it was used (`rico:hasOrHadPlaceName`, `rico:hasOrHadAgentName`), a place's coordinates as `rico:Coordinates` with altitude, a repository's every ISDIAH element as its scope note says, its type and dates. A place's history is not written: RiC-O 1.1 gives `rico:history` to agents only.
- **The RiC-O import reads it all back**: places, rules and activities as entries, every relation by its property, choosing the type whose pair fits the two ends, with what its qualified node adds; relations are linked once every entry exists, the qualified mention first. Relations an import makes are proposed, for review where they arrive.
- **What an export leaves out is listed**, found by reading the export itself rather than by a second list of each format's reach (`App\Exchange\ExportLoss`): on the export page, and for every format and RiC-O as JSON. The XML, CSV and RiC-O imports already list what they do not read.

## Consequences

The RiC scenarios of the gap assessment and two of the design review (`tests/Feature/RicScenariosTest.php`) run in CI: a changing creator context, several instantiations, a contested creator, one record in several contexts, a loss-aware exchange, a place's names over time and a repository's moves. Public name search now finds every published name of an entity, not only its authorised one. The departures found in the specification are recorded in `conformance/README.md` and go to openric/spec as issues, with Dr Johan Pieterse's approval. Not decided here: one record in several record sets at once (the design review's scenario E), and functions as entities for Digital Object Linkage.

## Evidence

- `tests/Feature/RicMappingTest.php`: every type mapped to terms that exist, agreeing with its scope note.
- `tests/Feature/GraphProfilesTest.php`: the place, rule, activity, list, subgraph, relation-list, relations-for and hierarchy schemas.
- `tests/Feature/ShaclGateTest.php`: the pinned shapes of six profiles, Graph Traversal's on the whole published graph.
- `tests/Feature/RuleTest.php`, `tests/Feature/RicScenariosTest.php`, `tests/Feature/ExportTest.php` (what each format leaves out), `tests/Feature/PortabilityTest.php`.
