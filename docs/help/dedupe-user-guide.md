---
title: Finding duplicate descriptions
category: Describing
order: 70
staff: true
routes: duplicates.*
heratio: dedupe-user-guide.md
---

# Finding duplicate descriptions

Catalogues gather duplicates: the same item described in two accessions, a fonds imported twice during a migration, two units given the same reference code by mistake. The duplicates screen finds likely pairs and lets a person decide. Nothing is ever changed or merged without someone choosing it.

The screen is under **Describe**, **Duplicate descriptions** (`/duplicates`).

## Rules

A rule says how to look for duplicates. Each has a kind:

| Kind | What it finds |
|---|---|
| Similar titles | Titles that read alike, above the threshold you set |
| Same identifier | Units with the same identifier |
| Similar identifiers | Identifiers that differ only slightly |
| Same date and creator | Units with the same dates and the same creator |
| Same file | Units with a file of the same SHA-256 |

Give each rule a threshold between 0 and 1 where it uses one, switch it on or off, and limit it to one repository if you like.

## Looking for duplicates

**Look for duplicates now** runs every rule that is on across the catalogue and says how many pairs it found that were not found before. Each pair is listed with its score, the rule that found it, and why.

## Deciding

Open a pair to **Compare** the two side by side: title, identifier, level, repository, dates, extent and scope and content, with what they share picked out. Then decide:

- **Merge the other into it**: choose which one to keep. The units inside the other move under it, it is given its own copy of each of the other's digital copies, and the other's relations move across. The other is deprecated as merged, its IRI pointing to the one kept, so nothing that cited it breaks. Merging needs the right to change the one kept and to delete the other, and a unit declared a record cannot be merged away.
- **Confirm, merge later**: mark them as duplicates without merging yet.
- **Not duplicates**: say why they are different. The pair is not offered again.

Duplicate authority records have their own screen; see [Describing authority records](/help/article/actor-manage-user-guide).
