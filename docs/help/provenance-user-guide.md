---
title: Provenance and custody history
category: Describing
order: 55
routes: records.provenance*
heratio: provenance-user-guide.md
---

# Provenance and custody history

Provenance answers the question every researcher and every claimant asks: who has held this, and how did it come to be here? The Workspace keeps a unit's provenance as an overview and a chain of steps. Each step is a `rico:Activity` that affected the record, with the owner or holder taking part as an agent, so the custody history is part of the record's RiC description and goes out with it.

Open it from a unit's page with **Manage provenance**, or **Full provenance** to read it (`/records/{record}/provenance`).

## The overview

**Edit the overview** records the summary of the record's provenance as a whole:

- how it was acquired, and when, with any price and its currency;
- its current status, custody type and current owner or holder;
- how certain the provenance is, from documented to unknown;
- whether there is a gap in the chain, and what is known of it;
- whether its Nazi-era provenance has been checked and, if so, the result;
- its cultural property status, such as claimed, disputed or repatriated;
- the state of research into it, with notes.

## The steps

**Add a provenance entry** adds one step in the chain: a sale, a gift, a bequest, an inheritance, a transfer, a loan, a deposit, a theft or a recovery, among others. For each, give:

- the date, exactly or in words such as *about 1920*, and its end date where it lasted;
- the agent who held it or received it, as an authority record;
- where it happened, if you know, with its Getty TGN identifier;
- for a sale, the auction house and lot number;
- how certain the step is, and the evidence for it;
- whether it marks a gap in the provenance.

Steps are shown in order of date and can be moved. Each is kept separately, so changing one never touches the others.

## Supporting documents

**Add a supporting document** attaches the evidence: a deed of gift, a bill of sale, an invoice, an auction catalogue or a certificate, as a file or a link, with its type and date.

## Reading and exporting it

The provenance shows on the unit's page as a short summary with a link to the full history. **Export CSV** gives every step as a spreadsheet. What is published of it reaches the public with the record, and every step goes out in the RiC-O export as an activity.
