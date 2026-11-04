---
title: Exporting
category: Import and export
order: 30
routes: records.export*, exports.*
heratio: export-data-user-guide.md
---

# Exporting

Whatever the Workspace holds can leave it, in the formats other systems read. Nothing is locked in.

## A unit and what is inside it

On any unit of description, **Export** offers:

| Format | What it holds |
|---|---|
| RiC-O | Everything: the unit, its relations, agents, places, activities and rules, as linked data |
| EAD 2002 and EAD3 | The finding aid: this unit and every unit inside it |
| CSV | This unit and every unit inside it, one row each, in AtoM's ISAD(G) columns |
| Dublin Core | This unit alone, as simple Dublin Core (oai_dc) |
| MODS 3.8 | This unit alone |
| METS 1.12 | This unit and every unit inside it, each described in Dublin Core, with the files you may receive and their SHA-256 |
| PREMIS 3.0 | Its preservation metadata: its instantiations, their files with SHA-256 and PRONOM format, and every event done to them |

Choose the language: a value not written in it goes in the language it was first written in. Choose **Include draft records** to take drafts too; otherwise only published values and names go.

## What each format leaves out

No format but RiC-O carries all of a RiC description. Under each format, the export page lists what of this unit it does not carry, found by reading the export itself, so you know before you send it. **Left out** downloads the same list for every format as JSON.

## Many at once

- **Every archival description** (`/records/export`): the catalogue, or one repository's part of it, as CSV in AtoM's columns, with a row for each language a unit is described in.
- **Every authority record as EAC-CPF**, or **every activity as EAC-F**, in a ZIP.
- **Every accession as CAAIS**, from the accessions list.
- A **vocabulary** as SKOS, from its page.
- An **authority record** alone as EAC-CPF, from its page.

## What you may export

An export holds what you may read and no more: only the units inside it that you may read, only accepted or disputed relations, and only those whose other end you may read. A file kept back by rights or an embargo is left out of METS and its manifest.

## Exporting for the public

The same formats are open to anyone, for what is published, through the OpenRiC API and OAI-PMH. See [Open data and harvesting](/help/article/oai-user-guide).
