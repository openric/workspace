# ADR 0016: Privacy and jurisdiction packs: POPIA governance first

- Status: accepted
- Date: 2026-10-31
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 18 copies Heratio's privacy, security clearance and national packs. Each is enabled per institution and decided by ADR. Done when a South African institution can show POPIA compliance for its catalogue. Together they are about 45,000 lines at `492745e31`:

- `ahg-privacy` (17,065 lines, 92 routes): registers, requests, breaches, impact assessments, PII scanning, redaction and compliance controls
- `ahg-security-clearance` (9,206 lines): classification levels, clearances, compartments, declassification, and two-factor sign-in
- the national packs:
  - NARSSA transfer packages
  - PSIS's SAHRA heritage permits
  - Zimbabwe's NAZ, NMMZ and CDPA
  - IPSAS heritage accounting

On 31 October 2026 Dr Johan Pieterse split the phase:

- **18a** POPIA governance
- **18b** privacy in the catalogue: personal information found and redacted
- **18c** security clearance
- **18d** South Africa's NARSSA and SAHRA packs
- **18e** Zimbabwe's NAZ, NMMZ and CDPA packs

IPSAS goes with heritage accounting in phase 21. Two-factor sign-in goes with single sign-on in phase 12.

## Decision (18a)

- **The registers** of Heratio's ahg-privacy, as tables of the institution:
  - Information Officers and deputies, with the registration with the Regulator (POPIA s.55, PAIA s.17).
  - The processing register, the documentation of POPIA s.17, with Heratio's RoPA fields and POPIA's own: lawful basis (s.11), special personal information (s.26), children's (s.34), transfers across borders (s.72) and security measures (s.19).
  - Personal information impact assessments.
  - Consent.
  - Data subject requests (s.23, s.24).
  - PAIA requests.
  - Complaints.
  - Security compromises (s.22), with their notices to the Regulator, data subjects and others.

  Every status, type, basis and ground is a vocabulary row (14 vocabularies), the PAIA grounds of refusal citing their sections.
- **One definition per register.** A register is defined once (`App\Privacy\Registers`): its fields, list and reference. Its list, form and record are made from that definition, where Heratio repeats a page set for each. The steps that are not edits are actions of their own:
  - a processing entry is submitted, then approved or rejected by someone other than who submitted it
  - an assessment is signed off once its measures are recorded
  - consent is withdrawn
  - a requester's identity is verified
  - a compromise's notices are recorded
- **References and due dates.** References run DSR-, PAIA-, CMP- and SC-YYYY-NNNN.
  - A data subject request is due the jurisdiction's days after it is received (POPIA 30, CCPA 45).
  - A PAIA request is due 30 days after (PAIA ss.25, 56), and 30 more once extended (s.26).
  - A compromise's Regulator must be told within the jurisdiction's hours (GDPR 72), or, under POPIA, as soon as reasonably possible (s.22(2)).
- **Jurisdictions** are reference data, seeded with Heratio's seven (POPIA, NDPA, Kenya DPA, GDPR, UK GDPR, PIPEDA, CCPA). So is the **compliance control catalogue**, Heratio's nine controls with their regime mappings, read only as Heratio's is.
- **The POPIA report** answers what the Regulator, an auditor or a board would ask, each with its evidence:
  - an Information Officer appointed and registered
  - processing documented and approved
  - impact assessments signed off where the processing needs them
  - reviews on time
  - requests answered in time
  - every reportable compromise reported
- **Who does this work.** A new institution permission, Manage privacy, is given to administrators: the Information Officer's work.

## Not carried in 18a

- Heratio's GDPR Article 30 export, its compliance autopilot and its data inventory: the processing register covers Article 30's content.
- Its own notification table: notices are the Outbox's when they come.
- Its templates.
- PII scanning and redaction come in 18b.

## Evidence

`tests/Feature/PrivacyTest.php` covers:

- the Information Officer and the processing register through submission and a decision by another
- an assessment signed off only with its measures
- consent withdrawn once
- requests numbered and due by their law, a PAIA extension, and the report's overdue count
- a compromise's 72-hour deadline under GDPR and its report to the Regulator
- the jurisdictions and controls, and refusal to an editor

## Amendment (v0.0.92, 31 October 2026)

The form audit of 31 October 2026 found four fields of Heratio's registers 18a had left out: a data subject request's priority (a vocabulary row) and notes, a PAIA request's fee exemption, and a security compromise's cause. They are added to the registers' definitions. Every field of the registers says that RiC-O 1.1 has no term for it.

## Decision (18b): privacy in the catalogue

- **The scanner** is Heratio's `PiiScanService` (`App\Privacy\PiiScanner`): patterns for email addresses, telephone numbers, national identity numbers, card numbers, IP addresses and dates of birth, with the checks that go with them:
  - a South African identity number's check digit (a wrong one is reported at low confidence, as possible, as Heratio does)
  - Luhn for cards
  - context words
  - an institution's own terms

  There is no model and no network. A scan that could not evaluate a pattern is not complete, so it never clears anything.
- **A scan reads** the record's names and description values, its files' embedded metadata, and the transcript of each file, and keeps what it found (at most 500 findings) with the jurisdiction it looked under. Its coverage says whether every file's text was read: "the description and the files" only when every instantiation with a file has a transcript.
- **Fields redacted for the public** (Heratio's `information_object_privacy_field`): each field of a record in full, in part by a pattern (email, telephone, the last four of an identity number, the year of a date), or pseudonymised (`Subject-` and six characters of a SHA-256, the same for the same value), with a reason, a legal basis reference and whether it is special personal information.
  - The redaction is applied where every public surface reads a unit's values (ExportView), so the catalogue, the API, JSON-LD, OAI-PMH, IIIF and the public search entry all say the same. The record keeps the original.
  - **Who reads the original:** an account that may change the record (it sees the value on its form anyway) or manage privacy. Heratio lets administrators only.
- **File screening** (Heratio's `FileScreeningGate`): an institution requires screening for a repository's records, or for a record and everything inside it. These are rows, where Heratio has two settings of ids.
  - There, a record's files are withheld from anyone who may not change it until its latest scan clears them: a person released them (risk accepted), or marked what was found redacted, or a scan that read every file found nothing and nobody has withheld them since.
  - Releasing needs a scan and a reason; withholding needs neither. The description is not affected.
- **Who does this work:** Manage privacy, as in 18a. The record page has a privacy panel for it, and the privacy pages a list of where screening is required, the scans that found something and wait for a decision, and the files withheld.

## Not carried in 18b

- Heratio's visual redaction of regions of an image (`visual-redaction-editor`): it needs the image server phase 14 decided against.
- Its named-entity recognition and its statistics tables.
- Its findings kept one by one with their own resolution: here the decision is on the record's latest scan.
- Scanning the text of a file that has no transcript: optical character recognition is phase 17.

## Evidence (18b)

`tests/Feature/PrivacyScreeningTest.php` covers:

- the scanner and the identity number check
- a scan of the description, embedded metadata and transcripts, and its coverage
- a redacted field redacted on the public page, in the API and in the public search entry, the original kept and shown to an editor
- the patterns
- the screening gate through scanning, releasing, withholding and clearing by a complete scan
- refusal to an editor
