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

## Amendment (roadmap step 12c), 3 November 2026

Added on Dr Johan Pieterse's word of 3 November 2026, after the RiC-O converter of the Archives nationales de France and Sparna was assessed (ArchivesNationalesFR/rico-converter 3.0.0, CeCILL-B, the reference conversion of EAD 2002 and EAC-CPF to RiC-O 1.1).

**The export gives RiC-O 1.1's specific relations beside the broad ones.** Every term was checked in the RiC-O 1.1 ontology file itself.

- **Direct inclusion.** `rico:directlyIncludes` (a record set's current, direct members) and `rico:isDirectlyIncludedIn` (where a unit is arranged now), beside `rico:includesOrIncluded` and `rico:isOrWasIncludedIn`. They are given only where the including unit is a `rico:RecordSet`, as the properties' domain and range require.
- **The kind of carrier.** `rico:hasOrHadDigitalInstantiation` and `rico:hasOrHadAnalogueInstantiation`, and on the instantiation `rico:isOrWasDigitalInstantiationOf` and `rico:isOrWasAnalogueInstantiationOf`, beside `rico:hasOrHadInstantiation`.
  - Digital is a carrier of the digital-file type, or, with no carrier type, a carrier with files kept.
  - Analogue is any other carrier type.
  - With neither, only the broad property is given.
- **Order.** `rico:directlyPrecedesInSequence` and `rico:directlyFollowsInSequence` between units arranged together, in their order, naming only units the reader may read: the public sees the units either side of one it may not read follow each other.

**Reading them back.** The Workspace's own RiC-O import counts a specific relation as read wherever its broad one is given beside it, as the export always gives it, since the broad one carries what the import takes. Without the broad one, as in data from the converter, a specific relation is still reported as not read.

**A development check against the converter.**

- `tests/Feature/RicoConverterComparisonTest.php` builds a fonds, exports it as EAD 2002, runs the converter on it, and compares, unit by unit, the RiC-O properties each side uses.
- Every difference is either fixed or written down as a choice, with its reason, in `tests/Fixtures/rico-converter-choices.txt`. One that is neither fails the test.
- It runs where `RICO_CONVERTER_DIR` names an unpacked converter release and Java is installed, and is skipped elsewhere, as in CI.

**What it found.** The relations above now match the converter. The choices recorded:

- **Dates:** the Workspace keeps each date with its type in its own date range (ADR 0006); the converter writes the one EAD date as literals on the unit.
- **Invented instantiations:** the converter makes an instantiation for every unit; the Workspace gives only the carriers it holds.
- **The finding aid:** the converter makes it a `rico:Record` describing the fonds; in the Workspace it is an export of the description.
- **The reference code:** the converter puts it on the instantiation it makes; the Workspace puts it on the unit (ISAD(G) 3.1.1).
- **Only the Workspace gives:** the kind of each carrier, which its EAD does not carry; the holder on every unit; titles as `rico:Title` (ADR 0019); and the structured extent.
- **About the converter itself, not properties:**
  - it reads EAD 2002 only as the DTD writes it, with no namespace, so the check takes the schema's namespace off the Workspace's EAD;
  - it tags every title and note as French, whatever the finding aid's language.

**BoDi.** BoDi, the Born-Digital Ontology, extends RiC-O 1.1 (http://w3id.org/bodi, version 1.0, Lucia Giagnolini, CC BY 4.0). The extension ontology states its close matches to BoDi, in a spec patch:

- `openricx:hasChecksum` to `bodi:hasHashCode`;
- `openricx:confidence` to `bodi:confidenceScore`;
- `openricx:humanConfirmed` to `bodi:hasHumanValidation`;
- a related match from `openricx:technicalCharacteristics` to `bodi:hasTechnicalMetadata`.

These are close matches, not equivalences: BoDi's confidence runs from 0 to 10 where ours runs from 0 to 1, its checksum is a PREMIS fixity, and its human validation is free text. Nothing in the Workspace depends on BoDi.

**Shapes.** The OpenRiC shapes note, at `sh:Info` and not as a violation, a record whose instantiations are not said to be digital or analogue, and a record set that includes units but does not say which directly. The roadmap asked for the shapes to require the specific properties. They cannot, without failing good data: a carrier of unknown kind has no specific property to give, and a past inclusion, or one by proxy, is not a direct one.

### Evidence (12c)

- `tests/Feature/RicSpecificRelationsTest.php`:
  - direct inclusion of record sets only;
  - sequence across a unit the public may not read;
  - digital and analogue carriers both ways, and the broad property kept.
- `tests/Feature/RicoConverterComparisonTest.php` with `tests/Fixtures/rico-converter-choices.txt`: no unexplained difference from ricoconverter 3.0.0.
- `tests/Feature/PortabilityTest.php`: the export read back with nothing left over.
- `tests/Feature/RicTermsTest.php`, `RicMappingTest.php` and `ShaclGateTest.php` unchanged: every term exists in RiC-O 1.1, and the pinned shapes pass.
