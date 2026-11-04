---
title: EAD and finding aids
category: Import and export
order: 20
routes: records.finding-aid, records.inventory
heratio: ead-import-and-finding-aid.md
---

# EAD and finding aids

Encoded Archival Description is how finding aids travel between archives. The Workspace reads EAD 2002 and EAD3, and writes both, so a finding aid can come in from AtoM or ArchivesSpace and go out to an aggregator such as the Archives Portal Europe.

## Bringing a finding aid in

Choose **EAD 2002 or EAD3** on a [new import](/help/article/data-ingest-user-guide). The import reads the archival description and every component inside it, keeping the hierarchy: each `<c>` (or `<c01>` to `<c12>`) becomes a unit inside the one above it, at its level. Names, places and subjects in its access points become authority records and concepts, matched to those already there by name.

The dry run report lists every element it could not take as given, such as a note type with no matching field, so you know what to check before importing.

## A finding aid out

From any record set, **Export** gives the unit and every unit inside it as **EAD 2002** or **EAD3**. Each value goes in the language you choose; one not written in that language goes in the language it was first written in. Only what you may read is exported, and drafts only if you ask for them.

## Reading and printing it

Two views of a record set are made for reading:

- **Finding aid**: the record set and everything inside it as one document, area by area, in the order of its arrangement, to read on screen or print.
- **Inventory**: a list of what is inside it, unit by unit, with each one's reference code, title, dates and level.

**Print view** gives one unit's description, set out to print.

## What EAD does not carry

EAD was made for finding aids, not for every relation RiC can hold. The export page says, for each format, what of the unit it leaves out: a relation between two agents, a rule, the evidence for a relation. **RiC-O** on the same page carries everything, and the list of what each format leaves out can be downloaded as JSON. See [Exporting](/help/article/export-data-user-guide).
