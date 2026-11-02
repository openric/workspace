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

## Amendment of 2 November 2026: security clearance (18c)

Dr Johan Pieterse chose 1A: clearance is checked in the one read check, beside embargoes, community protocols and file screening, so every surface that reads a record (the record pages, search, the API, JSON-LD, OAI-PMH, IIIF, exports, the shop and file delivery) answers the same.

- **Levels and compartments** after Heratio's `security_classification` and `security_compartment`: Heratio's six levels are seeded per institution and can be changed; a compartment names the lowest level that may hold it.
- **Clearances.** An account is cleared to one level, with compartments, until a date or without one. Nobody clears themselves. Every grant, change, revocation and ending is kept in the clearance history.
- **Classified records.** Only someone cleared to the level classifies a record, with a justification where the level asks for one. A classification holds for what is inside the record unless it says otherwise; the nearest classified ancestor decides. A record has a review date and a declassification date, and the daily run declassifies or downgrades it.
- **Reading.** A classified record, its files and its search hits reach only an account cleared to its level with all its compartments. The public never reads one. A level that forbids downloads keeps master files back from the cleared reader too.
- **Access above one's clearance** is asked for on a record, granted for a number of hours or denied by an administrator, and can be revoked.

Not carried in 18c: Heratio's watermark log (watermarks are phase 14's), its own retention and compliance logs (phases 13b and 13c), and two-factor sign-in (phase 12).

## Amendment of 2 November 2026: the South African and Zimbabwean packs (18d, 18e)

Dr Johan Pieterse chose 2A: NARSSA, NAZ and the CDPA now; of PSIS's SAHRA plugin, only the export permits of the National Heritage Resources Act's section 32, since its archaeology, palaeontology, structures and burial permits are not an archive's work; NMMZ goes to phase 21 with the heritage objects it registers.

- **Packs chosen in the settings.** Each pack (NARSSA transfers, export permits, the National Archives of Zimbabwe, the CDPA) is a row of a vocabulary, and an institution switches it on or off in its settings, as this ADR's context asked. A pack switched off has no menu entry and its pages answer 404; what it holds is kept. For the CDPA, the licence register and the CDPA as a jurisdiction to choose disappear. Asked for by Dr Johan Pieterse on 2 November 2026.
- **NARSSA transfers** after Heratio's `ahg-narssa`: records, or every approved transfer disposal not yet transferred, packaged as a tar.gz with each record's description as EAD3 and its master files, a manifest and a METS file with a SHA-256 for each record. The package is kept in the object store with its own SHA-256. Transmitted, then accepted with the receipt's reference, or returned. Changed: Heratio wrote the package with a shell `tar` command; here PHP's own archive classes write it, and reference copies never go in.
- **Export permits** after PSIS's `SahraPermitService`: an application names the records to be exported by IRI, where they go, the country and the period. It is approved within the institution by someone other than the applicant who may change every record it covers (PSIS's supervisor endorsement), lodged with SAHRA or a provincial authority, and issued with the permit number and conditions, or declined. An issued permit expires when its period ends, is revoked, or is closed when the records are back. Its documents are kept in the object store.
- **The National Archives of Zimbabwe** after Heratio's `ahg-naz`: a closure period or a protection is kept as an embargo on the record (and on what is inside it if asked), so the existing read check enforces it; a ministerial closure needs the order's reference and a protection a reason. Research permits are approved by someone other than who entered them and become active and expire by the daily run. Records schedules and transfers are kept with Heratio's fields; a schedule may name a disposal class of the institution's own retention schedule.
- **The Cyber and Data Protection Act** after Heratio's `ahg-cdpa`: a jurisdiction of the 18a privacy registers (POTRAZ, 30 days to answer a request, 72 hours to notify a breach), rather than a second set of registers; data controller licences are a new register, expired by the daily run; the Data Protection Officer gains qualifications, certificate number, term and form DP2.

Not carried in 18d and 18e: Heratio's NAZ transfer items (a transfer's description and quantities stand for them), a licence's certificate file and its renewal reminder.

## Evidence (18c, 18d, 18e)

- `tests/Feature/SecurityClearanceTest.php`: levels, compartments, clearances, classification with inheritance, the read check on every surface, access above one's clearance, declassification.
- `tests/Feature/JurisdictionPacksTest.php`: packs switched on in the settings and refused when off; a NARSSA package's contents and checksums and its workflow; an export permit from draft to issued, expired and closed, with its documents; a NAZ closure kept from the public and released, research permits, schedules and transfers; a CDPA licence expiring.
- `tests/Feature/AccessMatrixTest.php`: every new address refused to another institution and to an account with no role.
