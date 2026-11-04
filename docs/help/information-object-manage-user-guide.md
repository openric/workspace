---
title: Describing units of description
category: Describing
order: 10
routes: records.*, publication.*, names.*
heratio: information-object-manage-user-guide.md
---

# Describing units of description

A unit of description is what most people mean by "a record" in an archive: a fonds, a series, a file or a single item. In the Workspace each is a RiC entity, a `rico:RecordSet` (a fonds or series), a `rico:Record` (an item) or a `rico:RecordPart` (a part of one), at the level of description you give it, with a persistent IRI that never changes and is never reused.

The screens are under **Describe**, **Archival descriptions** (`/records`).

## The hierarchy

Units sit inside one another: a fonds holds series, a series holds files, a file holds items. Each unit names the one above it, and the hierarchy panel on a unit's page shows where it sits, with the units above and inside it. To arrange a unit elsewhere use **Move**; its whole branch moves with it.

A unit can also be included in another record set without moving it, with **Put it in another record set as well**. Its reference code, holder and what it inherits still come from where it is arranged; the other set simply lists it too, as RiC allows (`rico:isOrWasIncludedIn`).

## Making a unit

1. Open **Archival descriptions** and choose **New**, or open the unit it belongs inside and choose **Add a unit inside**.
2. Give its level of description, its title and its identifier. The level comes from the levels of description vocabulary, so an institution can add its own.
3. Choose the repository that holds it, if it is a top-level unit. Units inside inherit it.
4. Save. The unit is a draft until it is published.

**Enter units in a grid** adds many units inside one at once, a row each; **Import units into it** takes a CSV or EAD file into that branch.

## What a description holds

The page follows the ISAD(G) areas, each field numbered as ISAD(G) numbers it and showing the RiC term it is recorded as:

| ISAD(G) area | What you record |
|---|---|
| 3.1 Identity | Reference code, title, dates, level, extent and medium |
| 3.2 Context | Creators, administrative or biographical history, archival history, immediate source of acquisition |
| 3.3 Content and structure | Scope and content, appraisal, accruals, system of arrangement |
| 3.4 Conditions of access and use | Access, reproduction, languages and scripts, physical characteristics, finding aids |
| 3.5 Allied materials | Originals, copies, related units, publications |
| 3.6 Notes | Notes, and the archivist's note |
| 3.7 Description control | Rules, status, level of detail, dates of description |

**Dates** are kept as RiC date ranges with what they are dates of: creation, accumulation or another. A record set's dates can be worked out from the units inside it with **Calculate dates from the units inside**.

**Creators** are authority records, joined to the unit through the activity that produced or accumulated the records. See [Describing authority records](/help/article/actor-manage-user-guide).

**Names and translations.** A unit can carry other titles, and every value can be written in more than one language. See [Translating a description](/help/article/record-translation-user-guide).

## Publishing

Each value, name, date and relation has its own state: draft, reviewed, published or withdrawn. Open **Status** on the unit to publish its drafts, mark them as reviewed, or withdraw what is published, in one language or all. **Publish readiness** lists what still stands in the way, such as a missing title, before you publish.

A value someone changes goes back to draft, so a published description only changes in public when someone publishes it again. The public catalogue, the API, OAI-PMH and every export read only what is published.

## Other things on a unit's page

- **Digital copies**: its instantiations and their files. See [Uploading files](/help/article/multi-file-upload).
- **Provenance and custody history**: each step of its custody. See [Provenance](/help/article/provenance-user-guide).
- **Rights, embargoes, privacy and share links**: see [Rights](/help/article/rights-management-user-guide).
- **Retention and records management**: see [Records management](/help/article/records-manage-user-guide).
- **Export**: the unit in RiC-O, EAD, Dublin Core, MODS and other formats. See [Exporting](/help/article/export-data-user-guide).
- **Finding aid**, **Inventory** and **Print view**: the unit and what is inside it, to read or print.
- **Versions** and **History**: each change, from the audit log.

## Who may do what

| Action | Who |
|---|---|
| Read what is published | Anyone, in the public catalogue |
| Read drafts | Contributors, editors and administrators |
| Create and change | Contributors and up |
| Publish | Editors and administrators |
| Delete | Editors and administrators; a published unit is deprecated rather than lost |

An institution can change what each role may do under **Admin**, **Roles**.
