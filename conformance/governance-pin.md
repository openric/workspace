# Governance pin

What the OpenRiC Workspace's published Linked Data means, and how that changes. This is the governance pin of the OpenRiC Governance profile (section 2). If a standard, version, namespace or format is not listed here, the Workspace does not commit to it.

## Pinned standards

| Layer | Standard | Pinned version | Role |
|---|---|---|---|
| Records model | RiC-O | 1.1 (2025-05-22) | Every class and property in the RiC-O namespace the Workspace writes is defined by RiC-O 1.1. |
| Vocabularies | SKOS | W3C Recommendation (2009) | The Workspace's vocabularies, at `/vocab/{scheme}.ttl`. |
| OpenRiC extensions | `openricx:` = `https://openric.org/ns/ext/v1#` | extension ontology 1.6.0 | Terms RiC-O does not define. The same namespace in JSON-LD, Turtle and RDF/XML. |
| OpenRiC | specification | v0.56.0 | The profiles the service description declares, and the mapping. |

PROV-O and CIDOC-CRM are not pinned: the Workspace does not publish them. A RiC-O version change is a governed change, never an automatic upgrade.

## Identifiers

An entity's public IRI is `{base IRI}/{segment}/{UUID}`. The base is set once per institution, the UUID is minted with the entity, and neither comes from a label, so the IRI is the same in every environment and never changes. The Workspace keeps no graph apart from its database, so there is no internal IRI layer: the RDF is made from the database on each request.

## Deprecate, don't delete

A row of an entity with a persistent IRI is never deleted (`persistent_iri_guard`). An entity deleted or merged gets an entry in the deprecation register (`deprecations`). If the public could read it when it went, its IRI answers 410 Gone with `owl:deprecated`, `dcterms:isReplacedBy` its successor where there is one, and the reason as `rdfs:comment`. An IRI is never given to another entity.

## The gate

Every build runs the test suite in CI. The suite checks every export against the pinned OpenRiC SHACL shapes with pySHACL, and every RiC-O term against RiC-O 1.1 and the extension ontology. A build that fails does not merge and is not released.

## Change process

A change to what the Linked Data means (a version bump, a new property, a namespace or mapping change) is proposed as an issue, decided by Dr Johan Pieterse with an ADR, checked by the gate, released with a version, and listed in the change feed at `/api/ric/v1/changelog`.
