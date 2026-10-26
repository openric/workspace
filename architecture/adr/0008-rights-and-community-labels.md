# ADR 0008: Rights and Local Contexts Labels as rules

- Status: accepted
- Date: 2026-10-27
- Deciders: Dr Johan Pieterse
- Gate: G2

## Context

WS-007 asks for rights on each file. The roadmap's phase 2 copies Heratio's rights (ahg-extended-rights: rights_record and rights_grant, after PREMIS) and rights holders (ahg-rights-holder-manage). On 27 October 2026 Dr Johan Pieterse also brought Traditional Knowledge and Biocultural Labels of Local Contexts into phase 2, as Heratio has them (ahg-icip). Orphan works stay for phase 18; embargoes and retention for phase 13.

RiC-O 1.1 has rico:Rule (with rico:hasOrHadRuleType and rico:isAssociatedWithRule) and the intellectual property rights relation (rico:hasOrHadIntellectualPropertyRightsHolder). It has no properties for PREMIS acts, copyright status or who applied a Label.

## Options

1. Rights as description values of the instantiation (its rights element, free text). This is what the Workspace had: nothing can be checked or exported as a statement.
2. Rights as their own rows, each a rico:Rule with a persistent IRI, after Heratio's rights_record and rights_grant. The standard lists (RightsStatements.org, Creative Commons, the Local Contexts Labels) are vocabularies whose concepts are identified by the lists' own IRIs.

## Decision

Option 2.
- A right is held by a unit of description or an instantiation. It has a basis (PREMIS rightsBasis), a RightsStatements.org statement, a Creative Commons licence, a copyright status, a jurisdiction (ISO 3166-1), the date its status was determined, a citation, dates in force, a holder (an authority record) and a note. Its grants say for each PREMIS act whether it is allowed, disallowed or allowed on conditions.
- A right is marked deleted, never removed, so its IRI keeps resolving.
- A community label is a Local Contexts Label applied to a record by its community or by the institution, with the community (an authority record), the Hub project and a note. A record carries each Label once.
- Every list is a bundled vocabulary: rights-basis, rights-statement, licence, copyright-status, rights-act, rights-restriction, tk-label and label-applier. The statements, licences and Labels are concepts with their official IRIs and their codes as skos:notation, so the export names them as their publishers do.
- Exported as rico:Rule, rico:isAssociatedWithRule and rico:hasOrHadIntellectualPropertyRightsHolder. Acts, copyright status and the applier are part of the rule's description, since RiC-O 1.1 has no terms for them. Shown in the public catalogue and in the files API, and exported in RiC-O (rico:isOrWasRegulatedBy, as the OpenRiC record schema names it), EAD3 (userestrict), Dublin Core (dc:rights) and MODS (accessCondition), from v0.0.59.

## Evidence

`tests/Feature/RightsTest.php` covers:
- a right with statement, licence, holder and grants, its IRI and audit;
- grants replaced on save;
- a deleted right kept;
- every refused value;
- a Label applied once, with its Local Contexts IRI, and a group of Labels refused;
- the screens, and their permissions. `tests/Feature/PublicRightsTest.php`: the public catalogue, the API (valid against the OpenRiC record schema), the files API and the EAD3, Dublin Core and MODS exports show only rights in force, name a holder only when the public may read it, and give statements, licences and Labels by their publishers' IRIs.

## Consequences

What a visitor may do with a file can be decided from its grants, which the public catalogue and the files API show. Heratio's data migrates field for field. If RiC-O 1.2 adds terms for acts or copyright status, the export can use them without changing the store.
