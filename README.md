# OpenRiC Workspace

An archival management application built on the International Council on Archives' Records in Contexts (RiC-CM 1.0.1, RiC-O 1.1), for an archive starting a digital environment of its own. Laravel 13 on PHP 8.3, with PostgreSQL 16 ([ADR 0002](architecture/adr/0002-laravel-and-postgresql.md)).

**Status: in development, not yet released for use.** Each release is tagged, tested and deployed to the demonstration at **[workspace.openric.org](https://workspace.openric.org)**, which holds a seeded test dataset and no real records. Two outside assessments in November 2026 found it ready for demonstrations and pilot preparation, not yet for an institution's authoritative records. Install it to try it, not to hold an archive's only copy of anything.

## What it does

An archivist describes and arranges holdings, links records to the people, families, organisations, activities, places and rules around them, each link a dated statement of its own with its evidence, attaches files, and publishes what may be published. Around that: accessions, donors and agreements; rights statements, embargoes, retention and disposal; security classification and community protocols; preservation in an OCFL storage root with PREMIS events, format identification, normalisation and scheduled fixity; full-text search with close matches and regular expressions; a public catalogue with a IIIF viewer; reports; research services; and an audit log in which every change is chained and signed. RiC-CM and RiC-O are its model ([logical model](architecture/logical-model.md)); ISAD(G), ISAAR(CPF), ISDIAH, ISDF, EAD and EAC-CPF are views of it and formats in and out, with OAI-PMH, ResourceSync, a documented API and an optional SPARQL endpoint. Its [OpenRiC profiles](conformance/) are tested in every release, and its pages for WCAG 2.2 AA.

What is supported and what is experimental, off until named, is in [MODULES.md](MODULES.md).

## Install

- On a server of its own: clone, then `sudo bin/install` ([INSTALL.md](INSTALL.md)).
- In Docker, with PostgreSQL beside it: [docker/README.md](docker/README.md).
- Back up with `bin/backup`, restore and verify with `bin/restore` ([deploy/runbooks/restoring.md](deploy/runbooks/restoring.md)).

## In this repository

- [ROADMAP.md](ROADMAP.md): every release, what each phase brought, and what is left;
- [SPECIFICATION.md](SPECIFICATION.md): the functional requirements, WS-001 onwards;
- [architecture/adr/](architecture/adr/): the architecture decisions;
- [governance/HERATIO-PARITY.md](governance/HERATIO-PARITY.md): area by area, what the Workspace does of Heratio's functions, and what is parked;
- [governance/CODE_PROVENANCE.md](governance/CODE_PROVENANCE.md): the ledger of code taken from elsewhere;
- [docs/capacity/](docs/capacity/): the capacity test at 100,000 units of description;
- [deploy/](deploy/): releasing, deploying (a deploy refuses a release whose CI jobs did not all pass, or whose tag is not signed), and the runbooks.

## How it relates to the rest of OpenRiC

- **The OpenRiC specification** ([openric/spec](https://github.com/openric/spec), [openric.org](https://openric.org)) is independent of this application. The Workspace implements its published, versioned profiles and takes any gap to openric/spec first; its needs do not redefine the standard. Implementation began before the Standard Acceptance Gate, by decision ([ADR 0003](architecture/adr/0003-implementation-before-the-s-gate.md)). Every decision is also published at [openric.org/application/decisions](https://openric.org/application/decisions/).
- **Heratio** ([heratio.theahg.co.za](https://heratio.theahg.co.za)) is a separate GLAM platform with RiC built in. Much of the Workspace's code is Heratio's, copied and rewritten onto RiC, each file recorded in the provenance ledger; the Workspace has its own schema and never depends on Heratio at run time. Both have the same copyright holder and licence, so the reuse changes neither. A migration from Heratio is parked until an export with a full sample exists.

## Licence

AGPL-3.0-or-later; see [LICENSE](LICENSE). Third-party code that comes with Heratio's is checked file by file before it is brought in.

## Contact

Dr Johan Pieterse, The Archive and Heritage Digital Commons Group (Pty) Ltd - johan@theahg.co.za
