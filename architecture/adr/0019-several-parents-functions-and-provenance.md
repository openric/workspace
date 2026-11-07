# ADR 0019: Several parents, functions, and the last V0.1 profiles

- Status: accepted
- Date: 2026-11-02
- Deciders: Dr Johan Pieterse
- Gate: G2, G5

## Context

After phase 23b (ADR 0018) three OpenRiC profiles the conformance matrix lists for V0.1 were still undeclared or unbuilt: Digital Object Linkage (blocked by functions, which the Workspace did not serve), and Provenance & Event. The design review of 2 November 2026 asked two more things of the model: that one record can be described in two record sets at once, each with its own order (its scenario E), and that the ISAD(G), ISAAR(CPF) and ISDIAH screens not stand as the canonical model. On 2 November 2026 Dr Johan Pieterse decided: allow several parents; label those screens as compatibility views; build functions, Digital Object Linkage and Provenance & Event.

## Decision

- **Several parents, as RiC-O 1.1's proxies.** A unit keeps one parent (`record_resources.parent_id`): where it is arranged, which gives its reference code and holder and what it inherits (embargoes, rights, access). Each further record set it is in is a `record_memberships` row: a `rico:Proxy` with its own persistent IRI (`{base}/proxy/{UUID}`), standing for the unit in that set (`rico:proxyFor`, `rico:proxyIn`), with the unit's place in that set's order. A membership adds a context, not an inheritance. Ended, it is marked so; its IRI answers 410. The unit's JSON-LD names every set it is in (`rico:isOrWasIncludedIn`); a set lists its proxies in order (`rico:includesProxy`, `rico:proxyDirectlyPrecedesProxyInSequence`); the import reads them back, the set holding no proxy for a unit being its parent.
- **A function is an activity described as one.** RiC-CM 1.0.1 makes a function an Activity, and the Workspace's activities are already described with ISDF's elements; a second kind would duplicate them. An activity marked as a function (`activities.is_function`) is served at `/functions` as `openricx:Function` (mapping section 6.4), and not at `/activities`, since a function is not a dated event.
- **Digital Object Linkage 0.7.0 is declared**: `/instantiations` and `/functions`, each instantiation with its title, carrier type, the master file's MIME type, size and SHA-256, the unit it carries and its relations (derivation among them); a unit names its instantiations by IRI.
- **Provenance & Event 0.8.0 is declared.** An activity's results and participants are written as stubs with their class, so the profile's type checks can read them, and who performed it is also a participant. The profile makes a date, and for a production a result and a participant, violations; the Workspace refuses to publish an activity without them, and says what is missing. A function is exempt.
- **Stubs carry the names of the entity's own description**, language-tagged as there, not a plain label: merged documents then hold one literal per name, not two, so the one-title shapes hold across a harvested graph.
- **Compatibility views.** Every form and view laid out by ISAD(G), ISAAR(CPF), ISDIAH, DACS, RAD, Dublin Core or MODS says it is a compatibility view: the description is kept as RiC entities and exported as RiC-O.

## Consequences

The Workspace declares eight OpenRiC profiles. Live data published before this release is not re-checked: an activity published without a date stays published until it is next published or withdrawn, and the deploy says nothing of it; an institution should look at its activities list. A unit's reference code still comes from its one parent; a record set it is also in gives it no second code.

## Evidence

- `tests/Feature/RicScenariosTest.php`: the design review's scenario E (two record sets, each with its order, through the page, the API and an exchange), and the compatibility label.
- `tests/Feature/GraphProfilesTest.php`: instantiations and functions against the Digital Object Linkage schemas.
- `tests/Feature/ShaclGateTest.php`: the pinned shapes of eight profiles, the instantiation, function and activity documents among them.

## Amendment of 3 November 2026: each title its own rico:Title

Richard Williamson (EGAD) answered on the Records in Contexts users list on 3 November 2026 that two language-tagged `rico:title` values cannot be taken as one title in two languages, and recommended at least one `rico:Title` instance per title, so a translation can be told from a second title of equal standing. Dr Johan Pieterse chose the same day to do so.

- Records, instantiations and rules publish each title as a `rico:Title` through `rico:hasOrHadTitle` (its domain in RiC-O 1.1 is exactly these three), with `rico:textualValue` in its language and the dates it was used, as agents and places already publish `rico:AgentName` and `rico:PlaceName`. The original comes first.
- The language-tagged `rico:title` (and a rule's `rico:name`) stay beside them: the profiles' schemas expect them, and Richard's advice to consumers is to query both patterns.
- RiC-O has no way to say that a title translates another. An extension term (`openricx:isTranslationOf`) is asked of the specification in [openric/spec#74](https://github.com/openric/spec/issues/74); until it is defined, the order is the only sign of the original.

Evidence: `tests/Feature/RicScenariosTest.php` (scenario S5: a record titled in English and Afrikaans publishes two `rico:Title` nodes, the original first, beside its two `rico:title` values).

## Amendment of 6 November 2026: an authority record is a record too; the extension declared

Richard Williamson answered again on 6 November 2026. There are two ways to treat an authority record, and they can stand together:

- **As a network of entities.** Migrate it into the network RiC-AG §5 maps out. The Workspace does this already: the agent, its names, its dates and its relations.
- **As a record in its own right.** It documents how an agent was described. Its documentary form is set to Authority record, and the activity it documents is named.

On names and titles, his "at least one `rico:Title`" was deliberate. One name or title per language, which is what the Workspace publishes, and a single one with structured language variants are both open to an implementation, and RiC will likely allow both. Where RiC stops, he advises an implementation to say which extension it uses, so that consumers can take it into account. Dr Johan Pieterse chose the same day to do both.

- **The authority record.** An agent with any of its ISAAR(CPF) control area (5.4) is published with `rico:isOrWasDescribedBy` a `rico:Record` (`{agent}#authority-record`) that carries:
  - its documentary form, Authority record (the record-documentary-form vocabulary);
  - `rico:describesOrDescribed` the agent;
  - the description identifier, its status as `rico:hasRecordState`, and the maintaining repository as its holder;
  - the rules and conventions as a `rico:Rule`, the dates of description as `rico:hasModificationDate`, and the institution identifiers, languages, sources, maintenance notes and level of detail as notes;
  - `rico:resultsOrResultedFrom` the activity of describing the agent (`{agent}#description`), performed by the maintaining repository, under those rules and dated.
- **Its title.** It takes the agent's authorised name, as the shapes ask of a record. Before this, the control area was not published at all.
- **The extension declared.** The dataset description (`/api/ric/v1/dataset`) lists the vocabularies its data uses (`void:vocabulary`) and says what `openricx:` adds where RiC-O 1.1 stops, including the title and name nodes of the 3 November amendment. The federation protocol document (`/open-data/protocol`) names the ontologies and that `openricx` extends `rico`.

Evidence: `tests/Feature/ShaclGateTest.php` (the council's authority record, conforming to all seven OpenRiC shapes).

