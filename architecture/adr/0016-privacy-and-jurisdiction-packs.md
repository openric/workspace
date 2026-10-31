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
