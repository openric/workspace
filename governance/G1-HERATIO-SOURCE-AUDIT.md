# G1 Heratio source audit

Status: draft for review by Dr Johan Pieterse. 19 October 2026. Nothing has been copied; this audit is what a copy would be checked against.

## What was audited

| Item | Value |
|---|---|
| Repository | ArchiveHeritageGroup/heratio |
| Commit | `74239a174a941ca2a6a53ed26395d7be7b4ab134` (v1.159.37, committed 17 October 2026) |
| Scope | every directory under `packages/`, PHP files only, `vendor/` and `node_modules/` excluded |
| Packages | 120 |
| PHP files | 4,775 |
| Lines | 906,173 |
| Tool | `g1_heratio_audit.py` (this folder), read-only |
| Per-package data | `g1-heratio-packages-74239a1.csv` |

: Audit baseline

The audit is mechanical. It reads licence declarations and headers, and counts references to the AtoM/Qubit core tables. It does not judge whether a file is good code or how it behaves, and it does not cover JavaScript, CSS, Blade outside PHP files, or migrations written in SQL.

## Licence and ownership

The repository's `LICENSE` is the GNU Affero General Public License version 3. That is the licence every file in it is distributed under, whatever its header says, and it is compatible with the Workspace's AGPL-3.0-or-later.

At package level the declarations are uneven. Of the 120 `composer.json` files, 87 declare no licence, 28 declare `AGPL-3.0-or-later`, 4 declare `AGPL-3.0` and 1 declares `Apache-2.0`. At file level, 1,928 PHP files carry an AGPL header, 273 carry a copyright line with no licence named, and 2,574 carry nothing at all.

Every copyright line found names Johan Pieterse, Plain Sailing Information Systems or The Archive and Heritage Group. No other person or company is named as a copyright holder. The one match for "proprietary" is a comment about non-proprietary open formats in `ahg-api/src/Controllers/MaturityController.php`, not a licence.

So ownership is clear, but the headers are not good enough to copy as they stand. A copied file has to arrive in the Workspace with its copyright and licence stated and its source commit recorded (see `CODE_PROVENANCE.md`).

## Code that came from AtoM

Heratio grew out of AHG's AtoM plugins, and its comments say so. 273 files contain a "ported from" or "based on AtoM" note. Read individually, almost all of them point at AHG's own plugins (`ahgThemeB5Plugin`, `ahgAPIPlugin`, `ahgSemanticSearchPlugin`, `ahgTiffPdfMergePlugin`, `ahgAIPlugin` and others) or at PSIS. That is AHG code moving between AHG products.

Four files mention Artefactual Systems, the AtoM steward, and none of them is copied code. Two are footer credits for the AtoM UI translations (`ahg-theme-b5` footer and culture switcher), one is the AtoM comparison page in `ahg-marketing`, and one is the translation lint command in `ahg-core`.

One group needs a human check. Twelve view files say they were ported from `ahgThemeB5Plugin` templates: 10 in `ahg-user-manage` (user list, show, edit, login and actions) and 2 in `ahg-menu-manage` (the user menu). Their sources are named `*.mod_cas.php` and `*.mod_ext_auth.php`, the variants AtoM's CAS and external-authentication plugins override, and an AtoM theme plugin normally overrides a template by copying Artefactual's original and changing it. So these may carry Artefactual's code underneath AHG's edits. (The 10 `ahg-marketplace` files with a similar note came from AHG's own `ahgMarketplacePlugin`.) AtoM is AGPL-3.0, so that would not block reuse, but Artefactual's notice would have to travel with the file. Until each is compared with AtoM 2.x's `arDominionB5Plugin` and core templates, they are on the prohibited-copy list below; the cheaper route for a login page and a user list is probably to write them fresh.

## Coupling to the AtoM schema

This is the finding that decides the most. Heratio stores its archival data in the AtoM/Qubit schema (`information_object`, `actor_i18n`, `term_i18n`, `slug`, `object_term_relation`, `status`, `digital_object` and so on), and 2,666 of the 4,775 files (56%) name at least one of those tables.

The Workspace will not use that schema. ADR 0001 proposes its own canonical store, and the plan's architecture rule is one write model designed for RiC. Any file that reads or writes AtoM tables can therefore be copied only as a reference for its behaviour, not as working code: its data access has to be rewritten against the Workspace's model.

| Proposed scope | Packages | PHP files | Lines | Files naming AtoM tables | Share |
|---|---|---|---|---|---|
| V0.1 candidate | 45 | 2,376 | 449,091 | 1,383 | 58% |
| Later: V1.0 and after | 42 | 1,017 | 169,353 | 559 | 55% |
| Later: jurisdiction packs | 8 | 284 | 44,603 | 123 | 43% |
| Out: AI (V2 and after) | 7 | 336 | 78,915 | 183 | 54% |
| Out: GLAM sector | 18 | 762 | 164,211 | 418 | 55% |

: Heratio packages by proposed Workspace scope

The scope column was assigned by package name against the Workspace's archival scope. It is a proposal for review, not a decision, and the per-package list is in the CSV.

## What this means for reuse

Three kinds of reuse come out of the numbers.

- **Copy with light change.** The few packages that barely touch the AtoM schema: `ahg-media-processing` (2 of 9 files name AtoM tables), `ahg-export` (2 of 11) and `ahg-pdf-tools` (4 of 12). These are the cheapest wins and still need a header and a ledger row each. Most packages are not in this group; `ahg-c2pa` (48 of 68) and `ahg-metadata-export` (47 of 59), for example, are not.
- **Port the behaviour, rewrite the data access.** Most of the archival core: description and arrangement (`ahg-information-object-manage`), authorities (`ahg-actor-manage`, `ahg-repository-manage`, `ahg-function-manage`), rights, access requests, the audit trail, OAI-PMH. The business rules and the archivist's workflow are worth carrying across; the queries are not.
- **Write fresh.** Anything where the Workspace's design already differs: authentication and access control (ADR to come; multi-tenancy as a security architecture), settings, menus, the theme, user management, and the 12 templates above.

The largest V0.1 candidates by size are `ahg-research` (79,899 lines), `ahg-core` (60,173) and `ahg-information-object-manage` (47,506). `ahg-research` is large enough that whether it belongs in V0.1 at all should be decided before anyone reads it for reuse.

## Measured again (26 October 2026)

The count above marks a file as coupled when it contains any of the words in the script's pattern. That pattern includes `status` and `slug`, which almost every file uses as an ordinary word. It overstates the coupling.

Measured on heratio-dev, counting only the AtoM table names and query calls that name AtoM tables:
- of 4,787 PHP files (913,501 lines), 988 files (21%) touch the AtoM schema;
- 12,989 lines (1.4%) name an AtoM table;
- no file carries Artefactual's or AtoM's copyright.

Most of Heratio can therefore be copied as working code, with only its queries rewritten. On 26 October 2026 Dr Johan Pieterse made reuse of as much Heratio code as possible the rule (ROADMAP.md, Next phases).

## Draft prohibited-copy list

For `CODE_PROVENANCE.md`, to be confirmed at G1:

1. Any file whose copyright or authorship is not Johan Pieterse, Plain Sailing Information Systems or The AHG, or cannot be established. None found at this commit; the rule stays for future audits.
2. The 12 views noted as ported from `ahgThemeB5Plugin` templates, in `ahg-user-manage` and `ahg-menu-manage`, until each is compared with AtoM's own templates.
3. Any code that reads or writes the AtoM/Qubit schema, as working code. It may be read for behaviour; its data access is rewritten.
4. Any code that calls Heratio, its database, its triplestore or its services at run time.
5. The packages proposed as out of scope (GLAM sector and AI) for V0.1, unless a decision record brings one in.
6. Anything copied without a ledger row, whatever its origin.

## Next steps

- Review the scope column and the three reuse kinds.
- Compare the 12 theme-derived views with AtoM 2.x.
- Re-run the audit at the commit actually copied from, and pin that commit in the ledger; this audit's commit is a baseline, not a licence to copy.
