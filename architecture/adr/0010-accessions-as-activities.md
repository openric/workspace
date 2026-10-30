# ADR 0010: Accessions as their own kind, exported as activities

- Status: accepted
- Date: 2026-10-29
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 9 brings Heratio's ahg-accession-manage and ahg-donor-manage into the Workspace: accessions with Heratio's CAAIS 1.0 profile, donors, donor agreements, the intake queue, appraisal and valuation, and deaccessions. RiC-O 1.1 has no accession class. KM held no earlier decision. Heratio keeps AtoM's tables: an accession, a donor (an actor with contact information), and relations between them.

## Options

1. Accessions as a kind of their own, with Heratio's fields and screens, exported as a rico:Activity.
2. Accessions as activities of an accession type, with Heratio's fields added to activities.
3. Accessions as record sets of what was received.

For donors there were two options: an authority record with contact points, or a donors table of its own as Heratio's screens have it.

## Decision

Option 1. Dr Johan Pieterse chose it on 29 October 2026, with donors as authority records and donor agreements as records (rico:Record) documenting the accession.

- An accession has a persistent IRI and is exported as a rico:Activity of the OpenRiC activity type transfer. Its number is its local identifier, unique in the institution. Its texts are description values of the accession elements, numbered after CAAIS 1.0 and grouped in CAAIS's sections. Its acquisition date and the dates of its material are dates.
- Columns hold its acquisition type, resource type, processing status and priority (AtoM's taxonomies, as vocabularies) and the repository that accepts responsibility (CAAIS 1.1).
- A donor is an authority record taking part in the accession in the OpenRiC donor role. Its contact points are its own. Each donor may carry a CAAIS 2.1.6 confidentiality, which withholds it from a CAAIS export for outside use.
- Relations link the accession to the creators of its material (new type `material-creator`), to the descriptions made from it (result), to accessions it is an accrual to (new type `accrual`) and to the containers it is kept in (storage).
- The repeatable CAAIS statements are rows of the accession: extents (3.2), languages (3.4), preservation requirements (4.3) and events (5.1, with AtoM's accession events). So are AtoM's deaccessions. CAAIS 7.2's dates of creation and revision are read from the audit log, not kept twice.
- Accessions have their own permission kind, `accession`, given to editors and administrators. No other role reads them. A relation list on any page now leaves out an end the account may not read, so a description made from an accession does not show it to a researcher or the public.
- Phase 9 is released in four parts: 9a accessions, CAAIS, donors and deaccessions; 9b donor agreements; 9c the intake queue, checklist, timeline, attachments and numbering; 9d appraisal, valuation, accession containers and accession rights.

## Consequences

- The CAAIS lists (extent type, unit, content type, carrier type, preservation type, event type, source confidentiality) are bundled vocabularies with CAAIS 1.0's examples. CAAIS asks each repository to keep its own lists. Heratio lets an administrator edit them in its Dropdown Manager; here a change is a vocabulary release, until local lists of these kinds are added.
- Donor contact details are an authority record's contact points, so the same person or organisation is one record whether it creates, donates or is a subject.
- The CAAIS export is Heratio's JSON serialisation (heratio-caais-json/1), since the CCA publishes no XML schema or JSON binding.

## Amendment, 29 October 2026: donor agreements (9b)

Dr Johan Pieterse chose on 29 October 2026 to keep a donor agreement as a kind of its own, not as a unit of description in the catalogue, so that agreements never appear among the holdings in browse, search, publication or the tree.

- A donor agreement has a persistent IRI and is exported as a rico:Record whose documentary form type is its agreement type (the 14 types Heratio seeds). Its number is its local identifier, unique in the institution. Its title and texts (description, scope, extent, general terms, special conditions, restrictions, conditions, attribution text, payment terms, termination reason, the contact details it gives, internal notes) are description values of the agreement elements, in each language.
- Its type, status, repository, the agreement it is part of and the one it supersedes, its nine dates, its parties' names and titles, its financial terms and whether it is a template are columns, as Heratio keeps them. The dates are columns, not dates, because the dashboard and the reminders filter on them.
- The donor is an authority record that created the agreement in the donor role (creation, rico:creationWithRole), since a deed is made by both its parties. The accessions it documents are documentation relations (rico:ActivityDocumentationRelation, rico:documents). The units of description it covers are relations of three new bundled types: covers and covers in part (rico:hasOrHadSubject) and refers to (rico:isRecordResourceAssociatedWithRecordResource).
- Its rights, restrictions, reminders and documents are rows, with Heratio's lists as bundled vocabularies (agreement right and permission, restriction type, POPIA category, document type, and the reminder type, recurrence, priority and status). Documents are kept by their SHA-256 through the file store and scanned like any file; a confidential one opens only for who may change the agreement.
- Agreements have their own permission kind, `agreement`, given to editors and administrators.
- A reminder is mailed through the Outbox, advance days before its date, once for each date, to accounts of the institution that may read agreements, or else to the account that made it. It is never mailed to an outside address or to the donor, as Heratio's command could. A reminder that recurs moves to its next date, and one whose recurrence has ended is completed. `workspace:agreements:remind` runs each morning at 07:00.
- Heratio's agreement history is the audit log. Heratio's logo for printing an agreement, its in-app notification and its MD5 checksum are not carried.

## Amendment, 29 October 2026: intake (9c)

Heratio's intake screens are placeholders over its tables, so 9c follows PSIS's ahgAccessionManagePlugin, where the intake works: its AccessionIntakeService and the numbering of its AccessionContainerService.

- PSIS's accession_v2, one to one with the accession, becomes columns of the accession: intake status, priority, assignee, the times it was submitted, accepted and rejected, the rejection reason and the intake notes. The statuses and transitions are PSIS's: draft to submitted; submitted to under review or returned; under review to accepted, rejected or returned; returned to submitted. Accepting is Heratio's finalise.
- When the institution asks for it, an accession is accepted only once a donor agreement documents it, through a documentation relation from 9b. Heratio's finalisationBlockers looked for an attachment of a category its own list does not have.
- The checklist, the timeline (its chain of custody) and the attachments are rows of the accession. Attachments go through the file store like agreement documents. The timeline records each step, assignment, attachment and deaccession and takes notes; the audit log still keeps every change.
- Checklist templates, the numbering masks and the accession settings are the institution's; the settings are columns of the institution, as the watermark settings are. Every institution is given Heratio's Standard Intake template.
- A number is given when none is typed in. It comes from the institution's mask ({YEAR}, {MONTH}, {DAY}, {REPO}, {SEQ:n}, and {TYPE} for an agreement's type prefix), with each sequence starting at 1 again each year, as PSIS's does. Sequences are taken under a row lock, skip numbers already typed in by hand, and with {TYPE} run separately for each agreement type.
- Appraisal, valuation, accession containers and accession rights, with their settings, are 9d.

## Amendment, 29 October 2026: appraisal, valuation, containers and rights (9d)

Heratio's appraisal, valuation and container screens are placeholders, and its rights screen saves only part of a right, so 9d again follows PSIS's ahgAccessionManagePlugin: its AccessionAppraisalService and AccessionContainerService.

- An appraisal, its scored criteria and the valuations are rows of the accession. The score is PSIS's weighted average. Appraisal templates are the institution's, and every institution is given PSIS's seeded Archival Appraisal. When the institution asks for it, an accession is accepted only once an appraisal recommends something other than pending (Heratio's require_appraisal). Valuations are kept for GRAP 103 and IPSAS 45: the latest is the current value, and the valuation report adds the current values up by currency.
- An accession's containers are the Workspace's storage containers, linked by the storage relation already used for physical storage. PSIS's separate accession_container would have put the same box in two lists. Containers gain PSIS's condition, weight and item count. PSIS's container items, lines not yet described, are not carried: what a container holds is the descriptions placed in it.
- An accession's rights are the Workspace's rights (rico:Rule), now held by an accession too. They gain what the right does to access, its conditions and whether it passes down. When the institution passes rights down, a description made from the accession gets a copy of each right that passes, naming the right it came from. It is a copy, as Heratio's helper describes it, so the description's right can change on its own; Heratio's accession_rights_inherited joined to one row instead.
- The add form takes every field of Heratio's: the donor, or a new donor with their primary contact, the creators, a date, an event, an alternative identifier and the descriptions made from it. It had taken only the number and the title.
