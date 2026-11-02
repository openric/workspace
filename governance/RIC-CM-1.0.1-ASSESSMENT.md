# RiC-CM 1.0.1: what changed and what it means for the Workspace

Assessed on 1 November 2026, roadmap phase 23, after the RiC standards gap assessment of the same day asked for it. Source: ICA-EGAD/RiC-CM, release `1.0.1` of 30 September 2026 (commit `5374d2f`), the file `current-version/RiC-CM-1.0.1.pdf`, section 1.9.

## Sources and method

| Version | File | SHA-256 | Size | Released |
|---|---|---|---|---|
| RiC-CM 1.0 | `previous-versions/RiC-CM-1.0.pdf` | `a3170c3f6a0447f1d65cd3b4dd1b1fbf281545b4fb392d45e6f16bba185715d5` | 2,268,166 bytes | 13 December 2023 (tag `v1.0`) |
| RiC-CM 1.0.1 | `current-version/RiC-CM-1.0.1.pdf` | `1eaefced74ad4d5601340cfcaade041588c4594cc6bc44b2c2e82552139f0771` | 1,971,647 bytes | 30 September 2026 (release `1.0.1`, commit `5374d2f`) |

Both from https://github.com/ICA-EGAD/RiC-CM, read on 2 November 2026. Method: the release notes and section 1.9 were read in full; then both PDFs were converted to text (`pdftotext -layout`) and every relation line (`RiC-R` with its name) of the two versions was listed and compared mechanically. The only differences are the added inverses of RiC-R080 to RiC-R086 (`is creation date of` to `intersects`, with their `has or had ...` and `occurred at date` inverses) and three lines broken differently by the page layout (RiC-R016i, RiC-R027i, RiC-R060i), whose text is the same. The domain change of RiC-R079 is in its specification, not its name, and is as section 1.9 says.

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

## Impact register

As the design review of 2 November 2026 (section 17, gate G0a) asks: each change against each part of the Workspace.

| Change | Semantic registry (relation types) | Database | Forms and relation editor | Vocabularies | JSON-LD and RiC-O export | OpenRiC profiles | Classification |
|---|---|---|---|---|---|---|---|
| R079 has author on Record Part | No authorship type | None | None | None | None | None | No impact now; RiC-O gap recorded, for openric/spec before an authorship type is built |
| R080-R086 inverses listed | Dates are entities (`rico:Date`), not relation types | None | None | None | `rico:hasCreationDate` written; the others not written | None | Documentation only |
| Typography, links | None | None | None | None | None | None | No impact |
| Sections 1.7, 1.9, 1.11, 7.1 | None | None | None | None | None | None | No impact |

## Decision

The Workspace states RiC-CM 1.0.1 as its conceptual baseline from v0.0.96 (this register added in v0.0.97), with RiC-O 1.1 unchanged as its ontology. No data, mapping or export changes. One action is open: an openric/spec issue on R079's domain in RiC-O 1.1, to be raised before any authorship relation type is built.
