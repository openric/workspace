# Code provenance ledger

Every file in this repository that was not written for it is listed here before it is committed: where it came from, at which commit, who wrote it, under which licence, and who cleared it. Nothing from Heratio or any other source is copied before gate G1 (the legal and architecture baseline) has passed and the file has an approved row below.

## Rules

- A file is either written for the Workspace, or copied with a row in this ledger. There is no third kind.
- A copied file keeps its original copyright and licence header and gains a line naming its source commit.
- Licences must be compatible with AGPL-3.0-or-later. Anything uncertain is rewritten, not copied.
- The prohibited-copy list (below) is checked before any copy; it names code that may not be reused, for example because its ownership is unclear or it depends on Heratio at run time.

## Pinned sources

| Source | Repository | Commit | Audited on | Audited by |
|---|---|---|---|---|
| Heratio (baseline audit only; nothing cleared to copy) | ArchiveHeritageGroup/heratio | `74239a174a941ca2a6a53ed26395d7be7b4ab134` | 2026-10-19 | draft, for review by Dr Johan Pieterse (see `G1-HERATIO-SOURCE-AUDIT.md`) |
| Heratio, the files in the table below (identical at `74239a1`; `ahg-acl` checked 20 October 2026) | ArchiveHeritageGroup/heratio | `a060339c592bfb4d903679899c04bd56c5fe5744` | 2026-10-19 | Dr Johan Pieterse (ADR 0004) |
| OpenRiC reference service v0.17.25 (the RiC-O term mapping only; nothing cleared to copy) | openric/service | `c58ed28e6165d011ad285a50d8af772c520d8168` | 2026-10-19 | draft, for review by Dr Johan Pieterse; licence headers on each file to be checked at G1 |

## Copied files

| File here | Source file | Source commit | Original authors | Licence | Change made | Cleared by | Date |
|---|---|---|---|---|---|---|---|
| `app/Audit/Crypto/JcsEncoder.php` | Heratio `packages/ahg-inference-receipts/src/JcsEncoder.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | Apache-2.0 | Namespace only | Dr Johan Pieterse | 2026-10-19 |
| `app/Audit/Crypto/KeyPair.php` | Heratio `packages/ahg-inference-receipts/src/KeyPair.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | Apache-2.0 | Namespace only | Dr Johan Pieterse | 2026-10-19 |
| `app/Audit/Crypto/Signer.php` | Heratio `packages/ahg-inference-receipts/src/Signer.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | Apache-2.0 | Namespace only | Dr Johan Pieterse | 2026-10-19 |
| `tests/Unit/Crypto/JcsEncoderTest.php` | Heratio `packages/ahg-inference-receipts/tests/Unit/JcsEncoderTest.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | Apache-2.0 | Namespace; `@dataProvider` annotation as a PHPUnit 12 attribute | Dr Johan Pieterse | 2026-10-19 |
| `tests/Unit/Crypto/KeyPairTest.php` | Heratio `packages/ahg-inference-receipts/tests/Unit/KeyPairTest.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | Apache-2.0 | Namespace only | Dr Johan Pieterse | 2026-10-19 |
| `tests/Unit/Crypto/SignerTest.php` | Heratio `packages/ahg-inference-receipts/tests/Unit/SignerTest.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | Apache-2.0 | Namespace only | Dr Johan Pieterse | 2026-10-19 |
| `app/Audit/AuditChain.php` | Heratio `packages/ahg-audit-trail/src/Services/ChainedAuditWriter.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | AGPL-3.0-or-later | Ported: Workspace schema and PostgreSQL, shared signing view, fails closed, own key (ADR 0004) | Dr Johan Pieterse | 2026-10-19 |
| `app/Console/Commands/VerifyAuditChain.php` | Heratio `packages/ahg-audit-trail/src/Console/Commands/VerifyChainCommand.php` | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems (1) | AGPL-3.0-or-later | Ported: Workspace tables, shared signing view, range options dropped (ADR 0004) | Dr Johan Pieterse | 2026-10-19 |
| `app/Access/AccessControl.php` | Heratio `packages/ahg-acl/src/Services/AclService.php` (`check`) | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems | AGPL-3.0-or-later | Ported: Workspace roles and permissions tables; a denial wins; an account's own permissions first; role inheritance; a unit's permission covers what is inside it (ADR 0005) | Dr Johan Pieterse | 2026-10-20 |
| `app/Access/PermissionMatrix.php` | Heratio `packages/ahg-acl/src/Services/AclService.php` (`bucketIoPermissions`, `applyAclForm`, `applyUserAclForm`) | `a060339c5` | Johan Pieterse, Plain Sailing Information Systems | AGPL-3.0-or-later | Ported: one implementation for roles and accounts; UUID scopes; repository scope as a column; actions from workspace.permission_matrix; scopes validated against the institution; audited (ADR 0005) | Dr Johan Pieterse | 2026-10-20 |

(1) Authorship from the package `NOTICE` and `composer.json` (`ahg-inference-receipts`) and the file headers (`ahg-audit-trail`), which name Johan Pieterse and Plain Sailing Information Systems. Heratio's git history records the commits under a placeholder committer (`Your Name <you@example.com>`), so the history does not establish authorship on its own. The Apache-2.0 licence and notice travel with the copies in `governance/licences/`. Rule 5 below is set aside for the three `ahg-inference-receipts` classes only, by ADR 0004.

## Prohibited-copy list

Draft from the G1 source audit of 19 October 2026, to be confirmed at G1:

1. Any file whose copyright or authorship is not Johan Pieterse, Plain Sailing Information Systems or The AHG, or cannot be established.
2. The 12 Heratio views noted as ported from `ahgThemeB5Plugin` templates (`ahg-user-manage`, `ahg-menu-manage`), until each is compared with AtoM's own templates.
3. Any code that reads or writes the AtoM/Qubit schema, as working code. It may be read for behaviour; its data access is rewritten.
4. Any code that calls Heratio, its database, its triplestore or its services at run time.
5. Packages proposed as out of scope for V0.1 (GLAM sector and AI), unless a decision record brings one in.
6. Anything copied without a row in the table above.
7. The RiC-O class and property choices in Heratio's `ahg-ric` (at the pinned commit and at `a060339c5`, 19 October 2026). They type nodes with names RiC-O 1.1 does not define, among them `rico:Production`, `rico:Accumulation`, `rico:Occupation`, `rico:Function`, `rico:DateRange` and `rico:RecordList`, which OpenRiC corrected from the RiC-O 1.1 namespace remediation of spec v0.37.0 to reference service v0.17.25. Term choices follow the reference service at the pinned commit above and the published extension ontology, and every build is checked as `conformance/README.md` sets out.
