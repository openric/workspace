# ADR 0001: An independent canonical store; RDF and search as projections

- Status: proposed
- Date: 2026-10-19
- Deciders: technical lead and product owner (to be named at G0)
- Gate: G1

## Context

The OpenRiC reference service today reads and writes a database it shares with Heratio, and its public SPARQL graph is a static load that does not follow that database (OpenRiC drift log, 16 October 2026). The Workspace must be deployable with no Heratio data, credentials or services, and must keep descriptions, relations, their provenance and their access rules consistent.

## Options

1. A relational database as the single source of truth, with the RDF graph and the search index rebuilt from it.
2. A triplestore as the source of truth, with relational tables for the interface.
3. Both written at once.

## Decision (proposed)

Option 1. One transactional relational store is canonical. The RDF projection (OpenRiC JSON-LD and, optionally, a triplestore) and the search index are derived from it and can be rebuilt at any time; neither is needed for V0.1. Writing two stores at once (option 3) is rejected because they drift, as the reference service shows.

PostgreSQL is the preferred candidate and MySQL the comparison; the choice is made on a timed spike (recursive arrangement, constraints, multilingual queries, access control and migration adapters), recorded as a later ADR.

## Evidence

From the G1 database spike (`architecture/spikes/db/`). MySQL 8.0.46 has run (19 October 2026): recursive arrangement, multilingual fallback and access filtering are all in single-digit milliseconds at 131,131 entities, but the database could not be made to refuse an arrangement cycle, because MySQL with binary logging refuses triggers to an application user, and it has no row-level security. PostgreSQL 16.15 ran the same workload at comparable speed (load 21.8 s against 16.4 s; queries 0.3 to 3.1 ms) and refused every invalid write, the arrangement cycle included, with row-level security, transactional DDL and deferrable constraints available. On this evidence PostgreSQL is recommended; row-level security under a second role and concurrent writers are still to be tested before the choice is recorded in its own ADR.

## Consequences

Relation provenance and access rules live in one place and are enforced in one place. Every public channel (API, export, graph, search) reads a projection that applies the same access rules. The cost is a projection pipeline that must be tested for completeness and for leaks.
