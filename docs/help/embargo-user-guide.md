---
title: Embargoes
category: Rights and access
order: 20
routes: embargoes.*, records.embargoes.*
heratio: embargo-user-guide.md
---

# Embargoes

An embargo closes a unit of description to the public for a time: a donor's restriction for 25 years, a file held back for privacy, a series closed during a legal hold. It is lifted on the day it ends, or by hand.

## Kinds of embargo

| Kind | What it withholds |
|---|---|
| Full | The unit and everything about it |
| Description only | The description; nothing of it is public |
| Files withheld | Its files, while its description stays public |
| No master | Its master files, while the viewing copies stay public |

## Imposing one

On a unit's page, add an embargo:

1. Choose its kind and its **reason**: a donor restriction, copyright, privacy, legal, commercial, research, cultural, security or other.
2. Give the dates it runs from and until; leave the end empty for it to last until lifted.
3. Write **what the public catalogue says** while it is in force, such as *Closed until 2051 at the donor's request*, and a note for staff.
4. Set when to review it, every so many months, and how many days before it ends to be warned.
5. Choose whether to impose it **on everything inside it** too, each unit then carrying an embargo of its own.
6. **Impose**.

An embargo takes effect at once: the public catalogue, the API, OAI-PMH, IIIF, ResourceSync, the SPARQL store and every public export stop showing what it withholds.

## Exceptions

An embargo can let some people through: a range of IP addresses, such as the reading room's computers, an account, or everyone with a role. Add an exception with who it is for, from when and until when.

## Lifting

An embargo with an end date is lifted by itself on that day; the daily run lifts every one that has ended and notes it in the audit log. **Lift** ends one early, with a note of why.

## The list

**Embargoes** (`/embargoes`) lists every embargo in force, what it withholds, why and until when, and those ending within 30 days, so a review can be planned before material opens.
