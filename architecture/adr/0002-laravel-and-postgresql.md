# ADR 0002: Laravel 13 on PHP 8.3, with PostgreSQL 16 as the canonical store

- Status: accepted
- Date: 2026-10-19
- Deciders: Dr Johan Pieterse
- Gate: G1

## Context

ADR 0001 makes one relational database the source of truth and leaves the engine and the application stack to be chosen. The Workspace is to be production grade from the first commit (hard constraint, `SPECIFICATION.md`), and it reuses code from Heratio and the OpenRiC reference service by copying it under the provenance ledger, never by depending on it.

## Options

1. **Laravel on PHP, with PostgreSQL.** Heratio and the OpenRiC reference service are Laravel, so their behaviour, and where the ledger clears it their code, can be carried across file by file. PHP 8.3, Composer and PostgreSQL 16.15 are already installed and supported on the host.
2. **Django with PostgreSQL.** A mature framework, but nothing could be ported from the existing PHP; every behaviour would be rewritten and retested.
3. **Node and TypeScript with PostgreSQL.** Matches the AHG Workbench, with the same cost as option 2.

## Decision

Option 1, on **Laravel 13**, the current major release (13.37.0 when the repository was scaffolded). Laravel 12, which the reference service runs, is past its bug-fix window and receives security fixes only until February 2027, too short for a product starting now. The database is **PostgreSQL 16**: on the G1 spike it refused every invalid write, an arrangement cycle included, which MySQL could not be made to do, and it has row-level security, transactional DDL and deferrable constraints.

## Evidence

The G1 database spike (`architecture/spikes/db/`, results for MySQL 8.0.46 and PostgreSQL 16.15). The stack was chosen by Dr Johan Pieterse on 19 October 2026.

## Consequences

Porting from Heratio and the reference service stays possible, and each copied file still needs a ledger row and its own data access rewritten for this schema (prohibited-copy rule 3). Laravel's migrations, queues and test runner are used as they are. PostgreSQL features (row-level security, check constraints, triggers) may be used freely, so the Workspace does not run on MySQL; a migration adapter from Heratio's MySQL is a separate component (WS-013). Still open from ADR 0001: row-level security under a second role, and concurrent writers, which the multi-tenancy decision needs.
