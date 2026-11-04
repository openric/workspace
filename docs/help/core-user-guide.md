---
title: Getting started with the OpenRiC Workspace
category: Getting started
order: 10
routes: home
heratio: core-user-guide.md
---

# Getting started with the OpenRiC Workspace

The OpenRiC Workspace is an archival description and management system built on Records in Contexts (RiC-CM 1.0 and the RiC-O 1.1 ontology). It does what Heratio does for an archive, from intake to the public catalogue, but every record it keeps is a RiC entity with a persistent IRI, and everything it publishes can be read as RiC-O.

This guide is the map for a first day. The other guides go into each area.

## What the Workspace keeps

Everything you describe is one of a small number of entity kinds:

| Kind | RiC class | What it is |
|---|---|---|
| Unit of description | `rico:Record`, `rico:RecordSet`, `rico:RecordPart` | A fonds, series, file or item, at whatever level |
| Authority record | `rico:Person`, `rico:Family`, `rico:CorporateBody` | Who created, received or is named in the records |
| Repository | `rico:CorporateBody` | The institution or department that holds them |
| Place | `rico:Place` | Where things happened, with its coordinates |
| Activity | `rico:Activity` | What was done: creation, accumulation, an accession, a function |
| Rule | `rico:Rule` | A law, policy or mandate |
| Instantiation | `rico:Instantiation` | A copy of a record: a scan, a recording, a file |

They are joined by relations, each typed by a RiC-O property, with its own dates and evidence. Every field on a screen shows the RiC term it maps to, so you can always see what you are recording.

## Finding your way round

Once signed in, the menu at the top groups the work:

- **Describe**: units of description, authority records, repositories, places, activities, rules and vocabularies.
- **Manage**: accessions, donors and agreements, storage, vendors, rights and embargoes.
- **Workflow**: tasks, reviews, proposals and the file plan.
- **Import and export**: imports, watched folders, exports, labels.
- **Reports**: the report builder and the standard reports.
- **Research**: the reading room, researchers, reproductions and the shop.
- **Public site**: static pages, articles and landing pages.
- **Admin**: users, roles, settings, preservation, federation and the audit log.

What you see depends on your role. A contributor describes; an editor also publishes; an administrator runs the institution. The **Help** link opens the guide for the screen you are on, in a new tab.

## Draft, then published

Nothing reaches the public until it is published. Each name, value, date and relation has its own state, so you can publish a description and keep one sensitive note as a draft. The public catalogue, the API, OAI-PMH, the SPARQL endpoint and every export read only what is published, and a published entity keeps its IRI for ever. If it is withdrawn it is deprecated, and its IRI then answers that it has gone, rather than vanishing.

## The public catalogue

Every institution has a public catalogue at its own address, with full-text search, a page for each published entity, IIIF viewers for its images, and its own public site of pages, articles and landing pages. The **Public catalogue** link in the menu opens it as a visitor sees it.

## The clipboard

While browsing, add any record to the clipboard. Open it from the menu to review what you gathered, save the set under a name, or export it.

## Languages

The interface is in English and Afrikaans; choose yours from the language menu. Descriptions can be written in any language, value by value, and the public sees each in the language it asks for where one exists.

## Keeping a record of everything

Every change is written to the audit log with who made it and when, and each row is signed and chained to the one before, so the log shows if anything was altered after the fact. Administrators read it under **Admin**, **Audit log**.
