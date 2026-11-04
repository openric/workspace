---
title: Accessions
category: Accessions and storage
order: 10
routes: accessions.*
heratio: accession-manage-user-guide.md
---

# Accessions

An accession records a body of material arriving at the institution: what came, from whom, when, on what terms, and what became of it. In the Workspace an accession is a RiC activity, a `rico:Activity` of acquisition, whose donor is an authority record and whose agreement is a record of its own. It is described in the terms of CAAIS, the Canadian Archival Accession Information Standard, and can be exported as CAAIS.

The screens are under **Manage**, **Accessions** (`/accessions`).

## Recording an accession

1. Choose **New**.
2. Give its **accession number**. Leave it empty to take the next one from the numbering sequence.
3. Give what arrived: its title, scope, extent and quantity, the resource type, and the digital file formats where there are any.
4. Give how it arrived: the acquisition type, the date, and the **related donor record**, an authority record for the person or body it came from, with their primary contact information.
5. Save.

Creation and revision dates and who made them (CAAIS 7.2) are recorded on every save. Events, such as a receipt, a transfer or a review, are added as rows with their type, date and agent.

## Intake

Each accession moves through an intake status: draft, submitted, under review, then accepted, rejected or returned. The **queue** (`/accessions/queue`) lists the accessions still being taken in, with their priority and who they are assigned to.

On the accession's page, **Intake** holds:

- its intake status, processing status and processing priority;
- the person it is assigned to;
- an **intake checklist**, from the institution's template, each item ticked off with who did it and when;
- intake notes and attachments: inventories, photographs, correspondence.

An institution can require that an accession is accepted only once a donor agreement documents it.

## Appraisal and valuation

**Appraisal** records the appraiser's judgement: its significance (low, medium, high, exceptional or of national significance) and a recommendation (accept, reject, partial or defer), with notes. An appraisal template brings its criteria, each with a weight, to score against.

**Valuation** records what it is worth over time, monetary, insurance or replacement value, by a method and in a currency. The latest valuation is its current value, for heritage asset reporting under GRAP 103 and IPSAS 45. The **valuation report** (`/accessions/valuation-report`) lists them all.

## Containers and rights

**Containers** lists the boxes, folders and other containers the accession arrived in, each with its barcode and where it is kept. See [Storage](/help/article/storage-manage-user-guide).

Rights recorded on the accession, such as copyright held by the donor or a restriction on access, can be carried over to the descriptions made from it.

## From accession to description

When the material is processed, **Create an archival description from this accession** makes a unit of description from it, taking the accession title (or number), the number as its reference code and its scope and content, at the level of description you choose. The unit and the accession stay joined, so either leads to the other.

## Deaccessions

A deaccession records material that leaves the collection: all of an accession or part of it, when, why and by whose authority.

## Exporting

**Export CAAIS** gives one accession as CAAIS XML; **CAAIS** on the list gives them all. **Export CSV** gives the list as a spreadsheet.

## Settings

**Accession settings** (`/settings/accessions`) sets the accession and agreement numbering masks and sequences, the default priority, the default checklist template and the checklist and appraisal templates, whether a new accession is assigned to whoever records it, and whether an agreement is required before acceptance.
