# ADR 0006: A RiC-native entity model; Heratio is the floor, RiC the measure

- Status: accepted
- Date: 2026-10-20
- Deciders: Dr Johan Pieterse
- Gate: G2

## Context

Dr Johan Pieterse, 20 October 2026: "Add what Heratio does not have and do not follow Heratio verbatim. We need RiC 100%." Heratio's functionality is the minimum the Workspace must offer; its data model, AtoM's, is not the model. Where AtoM keeps names, dates, places and relations as text or terms, RiC-CM 1.0 and RiC-O 1.1 make them entities and reified relations. Authority records, repositories and archival descriptions are built on this model, and so is everything after them.

Decisions for authority records (20 October 2026): evidence and assertions on relations are built in from the start; external authority lookups (Wikidata, VIAF, ULAN, LCNAF, ISNI) are parked, not discarded; AI name recognition is out of scope.

## Decision

Every term below is defined in RiC-O 1.1 unless it says otherwise; a gap goes to openric/spec as an issue before the Workspace relies on its own term (ADR 0003, rule 2).

1. **Entities.** Record resources (`rico:RecordSet`, `rico:Record`, `rico:RecordPart`); agents as `rico:Person`, `rico:Family`, `rico:CorporateBody`, `rico:Position` and `rico:Mechanism` (all `rico:Agent`); repositories as corporate bodies that hold records; `rico:Place`; `rico:Concept` for subjects and other access points; later `rico:Activity`, `rico:Rule`, `rico:Mandate`, `rico:Instantiation`. Each has its own persistent IRI.
2. **Appellations are entities, not columns.** A name is a `rico:Name` (`rico:AgentName` for an agent, `rico:PlaceName` for a place) with its language, the dates it was used (`rico:usedFromDate`, `rico:usedToDate`) and a name type (authorised, parallel, standardised, other). RiC-O 1.1 has no property for the name type: proposed to openric/spec as an extension term (openric/spec#64). An identifier is a `rico:Identifier` with a `rico:IdentifierType` (`rico:hasIdentifierType`).
3. **Dates are entities.** A `rico:Date` with its written form (`rico:expressedDate`), a normalised value (`rico:normalizedDateValue`, ISO 8601 or EDTF), a qualifier (`rico:dateQualifier`, such as circa) and a type (`rico:hasDateType`, from the OpenRiC date-type vocabulary). An entity's dates are linked to it by the RiC-O property for their meaning (`rico:hasBeginningDate`, `rico:hasEndDate`, `rico:hasCreationDate`, `rico:isAssociatedWithDate` and the others). The record dates of v0.0.7 move into this model.
4. **Relations are reified RiC relations.** One table holds every relation between two entities: source, target, a relation type that names its `rico:Relation` subclass and shortcut property (such as `rico:AgentHierarchicalRelation`, `rico:FamilyRelation`, `rico:CreationRelation`), a role (`rico:creationWithRole` or `openricx:relationHasAgentRole`, role-type vocabulary), its dates (`rico:relationHasDate`), context (`rico:relationHasContext`), state (`rico:relationState`), certainty (`rico:relationCertainty`) and source of information (`rico:relationSource`). A record resource that proves a relation is linked to it with `rico:evidences`.
5. **Assertions from the start (WS-006).** A relation is held through one or more assertions: who asserted it (an account, an import or a model), when, on what basis, with what review status (a concept), and which earlier assertion it supersedes. Competing assertions about the same relation coexist; superseding keeps the old one. A machine assertion exports with the Inferred-Provenance markers (extension ontology 1.4.0). This goes beyond Heratio, by decision.
6. **Access points are relations to entities.** Subjects are `rico:Concept` entities (`rico:hasOrHadSubject`); places are `rico:Place` entities with their own names, dates and hierarchy; occupations are `rico:OccupationType` concepts (`rico:hasOrHadOccupationOfType`). Institutions add and edit them; they are not free text.
7. **Heratio is the floor.** Every function of the corresponding Heratio module is present, in the Workspace's own screens. A function Heratio does not have, or a data structure RiC needs that AtoM lacks, is added and recorded here or in a later ADR.

## Consequences

The ISAD(G), ISAAR(CPF) and ISDIAH screens stay organised by their standards, but what they write is RiC: a field such as "dates of existence" writes `rico:Date` entities and the relation that connects them. Export to OpenRiC JSON-LD becomes a direct reading of the store. The cost is more tables and more joins than AtoM's flat model, and a migration of the v0.0.7 record dates.

## Implementation notes

- **v0.0.10, dates.** One `dates` table for every entity; the date-property vocabulary names the RiC-O property of each link; the normalised value is EDTF, with its earliest and latest day derived for sorting.
- **v0.0.11, names and identifiers.** Name elements of the ISAD(G) and ISDIAH forms (`config workspace.name_elements`) write `rico:Name` entities; the name type waits on openric/spec#64. One authorised name per language and one local identifier per entity are enforced in the database.
- **v0.0.12, relations and assertions.** Relation types are checked against the RiC-O 1.1 ontology file: each concept names its Relation subclass, `_role` property and shortcut. `config workspace.relation_types` says which kinds each connects, as source and target pairs, in the direction of the shortcut, whichever page the relation is made from. A person's assertion is accepted as made; an import's or a model's is proposed until a person reviews it. A relation's standing is derived from its current assertions: accepted, disputed, proposed or rejected. Assertions are never edited, only superseded.
- **Kept as columns, exported as relations.** The arrangement of units (`parent_id`, exported as `rico:hasOrHadPart`) and the current holding repository (`repository_id`, `rico:hasOrHadHolder`) stay columns: the cycle and ordering guards depend on them. Former holders and other custody are relations.
- **Context.** `rico:relationHasContext` is stored and checked in the database. It has no screen until activities exist, because an activity is what a context usually is.
