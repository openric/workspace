# ADR 0015: Embargoes on records and their files; retention and disposal to follow

- Status: accepted
- Date: 2026-10-31
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 13 copies the embargo service, notices and reports of Heratio's `ahg-extended-rights` (at `492745e31`), extending embargoes from agents (which have one date, `embargo_until`) to records and files. It also copies the retention schedule and disposal workflow of `ahg-records-manage`. Done when a disposal is approved, carried out and reported, with nothing removed outside it.

`ahg-records-manage` is a full records management system for current records (10,691 lines, 70 routes): file plans and their import, email capture, classification rules, record declaration, vital records, compliance assessments.

On 31 October 2026 Dr Johan Pieterse chose:

- **The split.**
  - 13a: embargoes.
  - 13b: retention schedules, disposal classes, review schedules, the disposal workflow and the destruction certificate.
  - The rest of `ahg-records-manage` goes to a later phase of its own.
- **What destruction does.** A record destroyed under a disposal keeps its description as evidence: its files are erased, a destruction is recorded as a `rico:Activity` affecting it, and its IRI answers 410 as deprecated (ADR 0013).

## Decision (13a)

- **An embargo is on a unit of description.** It has a type and a reason, both vocabulary rows (`embargo-type`, `embargo-reason`), a first and a last day (none: until lifted), a note for staff, and what the public catalogue says while it is in force.
  - It is in force by its dates unless lifted, so nothing has to run for it to begin or end. Heratio keeps a status the daily run changes.
- **What each type withholds.** These follow Heratio's `canViewMetadata`, `canViewThumbnail`, `canViewDigitalObject` and `canDownload`:
  - full: the record itself
  - description only: every file
  - files withheld: the reference copy and the master
  - no master: the master
- **Who it binds.** An embargo binds who may not change the record, as Heratio's binds who is not an editor or administrator. It binds them in:
  - the public catalogue and the API, through `ExportView::readable`
  - staff pages and lists, through `EntityKinds::readable` and the record's guard
  - file delivery, through `InstantiationService::deliverable`, which also covers IIIF

  It withholds even from an approved access request.
- **Exceptions** open an embargo to one account, one role or one IP range (a reading room), for a time, as Heratio's `embargo_exception` does. Repository exceptions are not carried: who works for a repository is a role.
- **Imposing on everything inside** a record makes one embargo for each, as Heratio's `createEmbargoWithPropagation` does, so each can be lifted alone.
- **The daily run** (`workspace:embargoes`, 06:40) warns once, within the warning days before an embargo ends, and gives notice once it has ended. Lifting tells at once.
  - Notices go to the account that imposed it, else to who may administer the institution, through the Outbox.
  - Heratio also writes to the donor's and the repository's addresses. The Workspace sends only to accounts, as for every notice.
- **The list** of the institution's embargoes shows those in force, those ending within 30 days, and all of them, with statistics by type and CSV, after Heratio's admin page and `EmbargoReportCommand`.
- **The audit log** keeps every change, as Heratio's `embargo_audit` did.

## Evidence

`tests/Feature/EmbargoTest.php`:

- each type withholding what it says from the public (page, API, each file) and from staff who may not change the record
- exceptions by account, role and IP range, and their refusals
- dates, lifting, and imposing on everything inside
- the daily run's warning and notice, once each
- the list, statistics, CSV and permissions

## Consequences

- Each check of a record looks its embargo up, so a long list makes as many queries; a large catalogue wants it kept with the record.
- 13b brings retention and disposal, under this ADR.

## Amendment, 31 October 2026: retention and disposal (13b)

- **Retention schedules, disposal classes, assignments, reviews.** These follow Heratio's `rm_retention_schedule`, `rm_disposal_class`, `rm_record_disposal_class` and `rm_review_schedule`. Status, trigger, action and decision are vocabulary rows (`retention-schedule-status`, `retention-trigger`, `disposal-action-type`, `review-decision`).
  - **Schedules.** A schedule is issued under its authority (a NARSSA standing disposal authority, say). It is drafted, given classes, and approved by the name and office of who approved it. A new version copies its classes and supersedes the old one once approved.
  - **Assignment and due date.** Only a class of an approved schedule is given to a record, one class per record. The retention runs from the class's trigger: the end of the record's dates of creation or accumulation, the day of assignment, or a day given for an event such as a file's closing. The due date is that day plus the class's years and months, unless another date is given with its reason.
  - **Due list.** A record whose due date has passed, and that is not already in a disposal or a review, is listed as due.
  - **Reviews.** A review decides to keep the record for now (with the next review's date), to retain it permanently, or to start its disposal to destroy or transfer, as Heratio's `complete` does.
  - **Not carried.** Heratio's retention policies of `ahg-integrity`, which queue records by ingest date, are not carried: the class is the policy.
- **The disposal workflow.** This follows Heratio's `rm_disposal_action`. A disposal is initiated, may be recommended, and is approved, cleared and carried out.
  - **Separation of duties.** Heratio's rule (#1374) is kept in the service and again by the database: no one approves their own disposal, clears what they approved, or carries out what they approved or cleared.
  - **Who takes part.** Who may delete a record takes part in its disposal. Only who administers the institution destroys a record, as Heratio keeps destruction to administrators.
  - **Legal holds.** The Workspace keeps no legal holds of its own, so clearing is the clearer's word that none applies.
- **Destroying.** A destroyed record keeps its description as evidence, as Dr Johan Pieterse chose.
  - **Records with parts.** A record with units inside it is not destroyed until they are disposed of.
  - **The certificate.** A certificate, numbered DC-YYYY-NNNNN as Heratio numbers them, keeps the record's title, reference code and IRI, the authority, the class, the method and witness, who approved and who carried it out, each file's name, use, size and SHA-256, and a checksum of them all.
  - **Custody history.** The destruction is a step of the record's custody history, a `rico:Activity` affecting it, with a new destruction type in `provenance-transfer-type`.
  - **Deletion.** The record's instantiations are marked deleted and the record is deleted as any record is, so it leaves every list and its IRI answers 410 with the certificate's number (ADR 0013).
  - **Erasing the files.** The files are then erased, after the certificate is kept. A file another record still uses is kept and marked so on the certificate, because files are stored once by checksum.
  - **Verification.** This is Heratio's `verifyDestruction`: the certificate, its number and checksum, the files gone, the record deleted, and the destruction in the audit log.
- **Transfer and retention.** A transfer is recorded with its destination as a step of the custody history, and the record stays described. Permanent retention ends the record's retention, so it never falls due again.

Evidence: `tests/Feature/RetentionTest.php`. It covers:

- The schedule's approval and the due date from the end of creation.
- An override refused without its reason.
- The due list and the review that starts a disposal.
- Four accounts through the workflow, with every forbidden pairing refused.
- Destruction: the certificate, the files erased but for the one another record uses, the custody step, 410 with the certificate's number, and a verification that passes.
- The refusals: a record with parts, and a non-administrator destroying.
- Transfer and permanent retention.

Consequences: the due list and reviews are pages, with no daily notice yet. Heratio's AIP on transfer to its own preservation store waits for phase 15 (preservation).

## Amendment (v0.0.92, 31 October 2026)

The form audit of 31 October 2026 found Heratio fields 13a and 13b had left out. Added:

- an embargo's review date and review interval (Heratio's `review_date`, `review_interval_months`), with the embargoes in force whose review date has come listed as Heratio's `getEmbargoesForReview` lists them;
- a disposal class's description and whether it is active, and a form to edit a class while its schedule is a draft (the route and the service had it; the page did not).

Their fields, and every other field of these forms, say that RiC-O 1.1 has no term for them: embargoes and retention are kept by the Workspace and not exported in RiC-O.

## Amendment, 2 November 2026: records management (13c)

The rest of Heratio's `ahg-records-manage`, unparked by Dr Johan Pieterse on 2 November 2026, who decided the two open questions the same day:

- **The file plan (option 1A).** Nodes as Heratio keeps them (function, activity, series, sub-series, class; code, title, description, disposal class, the retention period and disposal action as an imported plan writes them, department and agency), each optionally one of the institution's functions or activities. A record is placed in one node, explicitly; Heratio found a node's records loosely, by disposal class or identifier prefix. A node's disposal class goes to a record placed in it that has none. A record in a node that is a function or activity is exported as documenting it (`rico:documents`, the shortcut of RiC-O's activity documentation relation; the e-mail to Dr Johan Pieterse named `rico:resultsOrResultedFrom`, but the Workspace's relation types keep that for a production activity's result, and a file plan's function documents a record rather than producing it). A place in a node that is no activity is the Workspace's own, and the export loss report lists it.
- **File plan imports.** A spreadsheet (CSV or Excel), its columns found by their headings as Heratio's `detectColumnMapping` finds them, each row's parent read from its code; a listing of folders, one path a line (Heratio read a folder of the server, which a web application should not); XML, EAD (`c` and `c01` to `c12` with `unitid` and `unittitle`) or any nesting of elements with a code. A row whose code is taken, repeated, or whose parent is missing is reported, not guessed. Placing records by their reference code is a choice made at import.
- **E-mail capture (option 2A).** Uploaded `.eml` and Outlook `.msg` files; no mailbox password is kept. The file is kept unaltered in the object store with its SHA-256, read for its sender, recipients, dates, subject, bodies and attachments, and captured once. Declared, it becomes an item under a record set of choice, described from the message, dated when it was sent, with the original as its instantiation, placed in the file plan and declared a record. The `.msg` reader is the Workspace's own (the compound file format and the few MAPI properties needed), since the PHP library that reads `.msg` needs the bcmath extension the host lacks; a library is the upgrade path.
- **Classification rules.** Heratio's six kinds (folder path, repository for Heratio's workspace, subject for its tag, file type, field value, department), highest priority first, tried on declaration, on capture or both; a regular expression that does not compile is refused when saved. Every placement a rule makes is logged.
- **Declaration.** Asked for, then approved or rejected by someone who takes part in retention and disposal; a workflow named "Record declaration" starts when the institution keeps one, as in Heratio. Changed: Heratio's declared record was a status only, though its own DoD 5015.2 check says a declared record cannot be changed. Here a declared record, and its instantiations, cannot be deleted except by its disposal once cleared.
- **Vital records.** Reason, review cycle in days, next review; reviewing sets the next.
- **Compliance assessments.** Heratio's six frameworks, check references, labels, weights and score (a warning counts half). Changed: Heratio's checks mostly asked whether a table existed, which every installation passes; here each reads the institution's own records management (an approved schedule, records placed and declared in the period, disposals approved and cleared before they were carried out, the audit chain verifying, vital records reviewed on time, creators and dates on records). The Workspace keeps no legal holds, and the DoD check for them says so rather than passing. An assessment is final once signed off.

Evidence: `tests/Feature/RecordsManagementTest.php` (a record placed in one node, its disposal class from the node, exported as documenting the node's function; imports from a spreadsheet with a repeated and an orphan row, a folder listing and EAD, records placed by code, bad XML refused; an `.eml` and an Outlook `.msg` captured, a second capture refused, a rule classifying on capture, the message declared an item with its date, original and declaration; a declared record refused deletion; a vital record overdue and reviewed; an ISO 15489 assessment failing, then passing as the institution does what it checks, final once signed off; DoD's legal hold check a warning), with the fixtures in `tests/Fixtures/mail`. `tests/Feature/AccessMatrixTest.php` builds every 13c object.
