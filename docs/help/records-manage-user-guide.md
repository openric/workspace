---
title: Records management
category: Records management and workflow
order: 10
staff: true
routes: records-management.*, retention.*, disposals.*, records.retention*, records.records-management
heratio: records-manage-user-guide.md
---

# Records management

Records management governs records as evidence across their whole life: how they are classified when they are made, how long they are kept, and how they are destroyed or transferred when that time is up, each step authorised and recorded. The Workspace carries Heratio's records management for a government body or a company that runs its records and its archive in one place.

## The file plan

The file plan (`/records-management/file-plan`) is the classification scheme records are filed under: a tree of nodes typed as function, activity, series, sub-series or class, each with its code and title. A node can be tied to the function it serves, a RiC activity, so the plan and the description share one account of what the body does. Each node can carry the disposal class that governs what is filed under it.

A file plan can be imported from a spreadsheet: upload it, map its columns, preview the tree, and commit.

## Retention schedules and disposal classes

A **retention schedule** (`/retention`) is issued by an authority, such as the National Archives and Records Service of South Africa, for a jurisdiction, with its reference, its dates and its version. It must be approved before it governs anything.

A **disposal class** within it says how long records are kept, from when (the end of their creation, the end of their accumulation, their assignment, or an event), and what happens then: destroy, transfer, retain permanently, or review.

Assign a class to a unit of description from its **Retention** panel, or let a classification rule do it.

## Classification rules

**Rules** (`/records-management/rules`) assign records to the file plan and to disposal classes by themselves, by what matches: a file's type, a word in its title, where it came from. A rule can be tried against one record before it is set loose on all.

## Declaring records

**Declaring** a unit makes it a record in the records management sense: it is locked, so it cannot be changed or deleted except by its disposal, once cleared. **Declarations** (`/records-management/declarations`) lists what is declared.

## Disposal

A disposal moves through its steps, each by someone with the authority and each recorded:

1. **initiated**, when the retention period ends or by hand;
2. **recommended**;
3. **approved**;
4. **legally cleared**, once nothing, such as a legal hold or a request under PAIA, stands in the way;
5. **carried out**: destroyed, with a certificate of destruction, or transferred;

or retained, rejected or cancelled along the way. A destruction purges the files from the object store; the unit's IRI remains, answering that it was disposed of.

## Reviews

A unit due for review appears in the review queue. The reviewer decides: keep for now, retain permanently, dispose or transfer.

## E-mail

E-mail is a record like any other. Upload `.eml` or `.msg` files under **E-mail** (`/records-management/emails`); each is kept with its original, its headers and its attachments, classified against the file plan and declared as a record.

## Compliance

**Compliance** (`/records-management/compliance`) records assessments against ISO 15489, ISO 16175, MoReq2010, DoD 5015.2, ISO 30300 or ISO 23081: their scope, findings, score and recommendations, signed off when complete.
