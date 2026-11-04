---
title: Rights
category: Rights and access
order: 10
routes: rights.*
heratio: rights-management-user-guide.md
---

# Rights

A right records what may be done with a unit of description or an instantiation, and on what basis: who holds the copyright, what licence it is under, what a statute or a donor agreement allows. The Workspace keeps rights in the PREMIS rights model, exports them as rights statements, and uses the right in force to decide what the public may do with a file.

## Adding a right

On a unit's or an instantiation's page, **Add a right**:

1. Choose its **basis**: copyright, licence, statute, donor agreement, policy or other.
2. Fill in what the basis needs:
   - for **copyright**, its status (in copyright, public domain or unknown), the rights holder, the jurisdiction and when that was determined;
   - for a **licence**, the licence, such as a Creative Commons licence, and its terms;
   - for a **statute**, the statute and its jurisdiction;
   - for a **donor agreement**, the agreement.
3. Choose its **rights statement** from RightsStatements.org, such as *In Copyright*, *In Copyright, Educational Use Permitted* or *No Copyright, Non-Commercial Use Only*.
4. Say what it allows or restricts, act by act: display, disseminate, replicate, migrate, modify, delete, print, publish or use.
5. Give the dates it is in force from and until, and a date to review it.

A unit can carry more than one right; the one in force today is the one that counts.

## The rights holder

A rights holder is an agent, an authority record, so the same holder can be named on many records and described once.

## In exports

The rights statement or licence in force goes out with the record: as the RightsStatements.org or Creative Commons IRI in RiC-O, Dublin Core, IIIF manifests and Europeana EDM, and as PREMIS rights statements in the PREMIS export.

## Many records at once

**Rights for the clipboard** (`/rights/batch`) gives every archival description on the clipboard the same right, and their instantiations too if you choose. It can also apply a Traditional Knowledge or Biocultural Label to each.

## The rights report

**Rights** (`/rights`) shows, for the institution:

- the rights in force today, by basis, by rights statement and by licence;
- the Labels applied;
- the rights holders;
- what has no right in force;
- the embargoes, and those ending within 30 days.

Each list downloads as CSV.

## Traditional Knowledge and Biocultural Labels

Labels from Local Contexts let a community say how material about it should be treated: who it belongs to, whether it is secret or sacred, whether it is open to all. Apply one on a unit with **Apply a Label**. A Label is shown with the record and goes out with its exports. Where access must actually be closed, use a community protocol on the concept or an embargo; see [Working with concepts](/help/article/term-taxonomy-user-guide) and [Embargoes](/help/article/embargo-user-guide).

## Watermarks

An institution can watermark the reference copies the public sees, by its own settings; the master is never watermarked, and neither is a thumbnail.
