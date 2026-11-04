---
title: Archivematica
category: Preservation
order: 70
staff: true
routes: archivematica.*
heratio: archivematica-integration.md
---

# Archivematica

Archivematica is a widely used open-source digital preservation system. An institution that runs one can use it beside the Workspace: the Workspace describes, Archivematica preserves, and each knows what the other did.

The screen is under **Admin**, **Archivematica** (`/archivematica`), set up by an operator.

## Setting it up

Give the addresses and API keys of the Archivematica Dashboard and Storage Service, and the transfer source location: a folder that both Archivematica and the Workspace's server can reach. **Ping** checks that both answer.

## Sending a unit

From a unit, send it as a **transfer**. The Workspace puts its files and metadata in the transfer source, starts the transfer through the Dashboard's API, and follows it through Archivematica's processing until its AIP is stored. The job, its steps and their outcomes are listed, and the AIP's identifier is kept with the unit.

## Taking DIPs in

DIPs that Archivematica made can be fetched from the Storage Service and taken in, their files becoming the unit's instantiations. Archivematica can also upload a DIP into the Workspace by SWORD, as it does into AtoM; see [Information packages](/help/article/dp-oais-packages).

## Processing configuration

Archivematica's own processing configuration decides what it does with a transfer. The Workspace does not ship one; an operator can supply the institution's.
