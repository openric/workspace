---
title: Europeana
category: Federation and open data
order: 50
staff: true
routes: federation.europeana*
heratio: europeana-publish.md
---

# Europeana

Europeana is Europe's digital cultural heritage platform. It takes records in the Europeana Data Model (EDM), through an aggregator. The Workspace exports an institution's published records as EDM, ready for its aggregator.

The screen is under **Admin**, **Federation**, **Europeana** (`/federation/europeana`).

## What it says of itself

Set the institution's **data provider** name (its own name unless set), its **country**, its **language** and its **default rights**, the RightsStatements.org or Creative Commons IRI used where a record has no right in force.

## Exporting

**Export now** writes every published unit of description, or those changed since a date, as EDM:

- an `edm:ProvidedCHO`: its title, reference code and IRI, scope and content, creators, subjects and names, genres, languages, dates, places, its EDM type, rights, extent and repository;
- its `ore:Aggregation`: the data provider, `isShownAt` its persistent IRI, `isShownBy` its reference copy, `object` its thumbnail, and `edm:rights`;
- an `edm:WebResource` for each file the public may receive;
- the `edm:Agent`, the `edm:Place` with its coordinates, and the `edm:TimeSpan` it names.

Its EDM type comes from its files: IMAGE, SOUND, VIDEO or 3D, else TEXT. Its `edm:rights` is the IRI of the right in force on the record, else the default.

## The bundle

The records and a sitemap of them go in one ZIP bundle, kept in the object store and downloaded with its SHA-256. Each run is listed with how many records it held and any error.

Europeana takes the bundle in through its aggregator's own ingestion, outside the Workspace.
