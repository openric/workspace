# OpenRiC Workspace

**Status: in development.** Laravel 13 on PHP 8.3, with PostgreSQL 16 ([ADR 0002](architecture/adr/0002-laravel-and-postgresql.md)). Nothing here is released.

OpenRiC Workspace will be an archival management application for archives starting a new digital environment, built natively on the International Council on Archives' Records in Contexts (RiC-CM 1.0.1, RiC-O 1.1; see [governance/RIC-CM-1.0.1-ASSESSMENT.md](governance/RIC-CM-1.0.1-ASSESSMENT.md)). An archivist will describe and arrange holdings, link records to the people, organisations, activities and places around them, record the evidence for each of those links, attach digital files, publish what may be published, and export it all through the [OpenRiC specification](https://openric.org).

This repository holds the application and its governance:

- [INSTALL.md](INSTALL.md): installing it on a new server, and [deploy/README.md](deploy/README.md): releasing and deploying;
- [ROADMAP.md](ROADMAP.md): releases, delivery gates and their status;
- [SPECIFICATION.md](SPECIFICATION.md): the functional requirements, WS-001 onwards;
- [architecture/adr/](architecture/adr/): architecture decision records;
- [architecture/logical-model.md](architecture/logical-model.md): the G1 logical model (draft, non-normative);
- [governance/V0.1-DEMONSTRATION-SCENARIO.md](governance/V0.1-DEMONSTRATION-SCENARIO.md): the V0.1 demonstration scenario and sample dataset (draft for freezing);
- [governance/CODE_PROVENANCE.md](governance/CODE_PROVENANCE.md): the ledger of any code taken from elsewhere;
- [conformance/](conformance/): the OpenRiC profiles the Workspace will implement, pinned to a specification version.

## How it relates to the rest of OpenRiC

- **The OpenRiC specification** ([openric/spec](https://github.com/openric/spec), [openric.org](https://openric.org)) is independent of this application. The Workspace implements published, versioned profiles; its needs do not redefine the standard.
- **Implementation started before the Standard Acceptance Gate (S-GATE)**, by decision ([ADR 0003](architecture/adr/0003-implementation-before-the-s-gate.md)). The Workspace builds against a pinned, released specification version and takes any gap to openric/spec first. See [ROADMAP.md](ROADMAP.md).
- **Heratio** ([heratio.theahg.co.za](https://heratio.theahg.co.za)) is a separate, integrated GLAM platform with RiC built in. The Workspace has its own schema and stack and never depends on Heratio at run time. Heratio's workflows may inform the design; any Heratio code is reused only after a file-by-file rights and dependency audit (gate G1), recorded in the provenance ledger. Heratio and the Workspace have the same copyright holder (Johan Pieterse / Plain Sailing Information Systems), and Heratio is published under the same licence (AGPL-3.0-or-later), so reusing its code changes neither ownership nor licence; third-party code that Heratio itself carries is checked file by file before it comes along. Migration from Heratio is a planned, one-way capability.

## Licence

AGPL-3.0-or-later; see [LICENSE](LICENSE). The licence is subject to the legal and provenance gate (G1) before any third-party code is brought in.

## Contact

Dr Johan Pieterse, The Archive and Heritage Digital Commons Group (Pty) Ltd - johan@theahg.co.za
