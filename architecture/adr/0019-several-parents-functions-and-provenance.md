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
