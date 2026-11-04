---
title: PREMIS and METS
category: Preservation
order: 50
staff: true
heratio: dp-preservation-metadata-premis-mets.md
---

# PREMIS and METS

## PREMIS

PREMIS 3 is the standard for preservation metadata: what a file is, what was done to it, by whom, and with what result. Every action on a file in the Workspace is a PREMIS event, typed by the Library of Congress preservation event vocabulary:

| Event | When |
|---|---|
| Ingestion | The file arrived |
| Message digest calculation | Its SHA-256 was taken |
| Virus check | It was scanned |
| Format identification | Its format was identified against PRONOM |
| Metadata extraction | Its technical metadata was read |
| Creation | A copy was made from it |
| Normalisation | A preservation master or access copy was made |
| Migration | It was migrated to another format |
| Fixity check | Its checksum was taken again |
| Deletion | It was removed |

Each event has its date, its outcome and its agent: a person, or the software with its version. Events are kept after the file is gone, so its history outlives it.

The **PREMIS 3.0** export of a unit gives its instantiations and their files, with SHA-256 and PRONOM format, every event done to them, and its rights as PREMIS rights statements. It validates against the PREMIS schema.

## METS

METS 1.12 wraps a unit and everything inside it into one document: each unit described in Dublin Core, the files you may receive with their SHA-256, and how they fit together. It is what information packages carry, and what many digital library systems take in. Export it from the unit's **Export**.

## Where to see them

A file's page lists its events. A unit's **Export** gives its PREMIS and METS. The PREMIS events also go out in information packages; see [Information packages](/help/article/dp-oais-packages).
