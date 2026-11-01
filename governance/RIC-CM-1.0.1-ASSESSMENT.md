# RiC-CM 1.0.1: what changed and what it means for the Workspace

Assessed on 1 November 2026, roadmap phase 23, after the RiC standards gap assessment of the same day asked for it. Source: ICA-EGAD/RiC-CM, release `1.0.1` of 30 September 2026 (commit `5374d2f`), the file `current-version/RiC-CM-1.0.1.pdf`, section 1.9.

## The changes

RiC-CM 1.0.1, prepared in August 2026, is a minor version. Section 1.9 lists four changes:

1. Typographical errors and the links in the notes are fixed.
2. **RiC-R079 "has author"** now has Record *or Record Part* as its domain; in RiC-CM 1.0 it was Record only. The range of its inverse ("is author of") changes to match.
3. Inverse relations missing from the list of relations (section 5.6) are added for **RiC-R080 to RiC-R086**: is creation date of, is or was creation date of all, some and most members of, is date of occurrence of, is within, and intersects. They were already in the chart and in the specification of each relation, so the model itself does not change.
4. Sections 1.7 (development), 1.11 (acknowledgements) and appendix 7.1 (EGAD members) are updated, and section 1.9 is added.

## Effect on the Workspace

| Change | In the Workspace today | Effect |
|---|---|---|
| R079 "has author" on Record Part | No authorship relation type exists. A record's creators are the `creation` relation (`rico:CreationRelation`), with roles. | None now. An authorship relation type, if added, takes records and record parts. |
| R079 against RiC-O 1.1 | RiC-O 1.1 still declares `rico:hasAuthor` with domain `rico:Record` only (checked in `RiC-O_1-1.rdf`). | RiC-O lags RiC-CM here. Raise with openric/spec before an authorship type is added, so a Record Part author does not fail SHACL. |
| R080-R086 inverses listed | The Workspace exports `rico:hasCreationDate` (R080's inverse direction) from a record's creation dates. The other date relations are not exported; dates are `rico:Date` entities on their subject. | None. The inverses were always part of the model. |
| Typography, links, acknowledgements | None | None |

## Decision

The Workspace states RiC-CM 1.0.1 as its conceptual baseline from v0.0.96, with RiC-O 1.1 unchanged as its ontology. No data, mapping or export changes. One action is open: an openric/spec issue on R079's domain in RiC-O 1.1, to be raised before any authorship relation type is built.
