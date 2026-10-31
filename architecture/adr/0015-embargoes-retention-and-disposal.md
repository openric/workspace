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
