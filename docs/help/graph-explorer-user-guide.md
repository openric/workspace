---
title: The graph explorer
category: Federation and open data
order: 70
routes: discover.explore, sparql
heratio: graph-explorer-user-guide.md
---

# The graph explorer

Searching finds records by their words. The graph explorer finds them by their relations: every series created by a member of a given family, every place a body was active in, every record that concerns a place and was accumulated by a given office. It is Sparnatural, the visual query builder FranceArchives and the Archives nationales use over RiC-O.

It is at `/discover/explore`, where the institution has the graph switched on.

## Building a question

1. Choose what you are looking for, such as **Record**.
2. Choose how it is joined to something else, such as **has creator**, and what that is, such as **Person**.
3. Narrow that, such as by a name, or a date.
4. Add more joins to go further, such as a person who **is or was member of** a **Corporate body**.
5. Run it. The answer appears below as a table, each entity linking to its page.

The kinds of entity and the joins between them come from the OpenRiC shapes and the Workspace's relation types, so the explorer offers only questions the catalogue can answer.

## SPARQL

Sparnatural writes a SPARQL query, and sends it to the institution's SPARQL endpoint, `/sparql`. Anyone can send their own queries there too, from any tool: SELECT, ASK, CONSTRUCT and DESCRIBE, answered within a time limit. An update is refused.

## What it holds

The endpoint and the explorer read a store of the institution's published RiC-O, one graph for each published entity, brought up to date every few minutes. A draft is never in it, and an entity withdrawn leaves it.

## Switching it on

The graph needs an Apache Jena Fuseki server, which an operator sets up; see the installation guide. Until then, the explorer says the graph is not yet open.
