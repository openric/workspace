# ADR 0012: EAC-CPF and EAC-F exchange, round trips by IRI

- Status: accepted
- Date: 2026-10-30
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 11a adds EAC-CPF import (the export exists) and EAC-F, which openric/service #14 asks for. Done when each format round-trips the demonstration data, every rejected field is listed, and nothing is lost silently. Heratio has export only: `EacCpf2Serializer` and `EacFSerializer` in `ahg-metadata-export` (at `492745e31`). Its EAC-F serializer writes a draft namespace that no published schema defines, and neither package reads EAC files back. The TS-EAS group published EAC-F as EAF 1.0.0 in its 2026-07 release (namespace `https://standards.openpreservation.org/eaf/v1`), with RELAX NG and XSD schemas under CC0.

## Options

1. Port Heratio's serializers and write importers to match them.
2. Write the EAC-F export from the EAF 1.0.0 schema, and both importers as the reverse of the Workspace's own exports.

## Decision

Option 2. Heratio's EAC-F output would not validate against the published schema, and there is no Heratio import to copy.

- EAC-F is EAF 1.0.0, validated in the tests against the release's built `eaf.rng` (`tests/Fixtures/eas-2026-07`).
- An activity's relations are written with the Workspace's relation type notations, declared as `otherRelationTypeEncoding`. EAF has no attribute for which end of a relation the other entity is, so the label's `localType` carries `source` or `target`. EAC-CPF carries it as `xlink:role`, which says which end the described record is. Both read back the right way round.
- Each record's persistent IRI is written as `otherRecordId localType="persistentIri"`. An import matches by that IRI first, then by its key from an earlier import. The key is the IRI, else `recordId`, else the file name.
- `recordId` is the description identifier, or the record's own id when it has none. On import, a `recordId` equal to the record's own id is not taken as a description identifier.
- Either format imports one file or a ZIP of files, and every authority record or activity exports as a ZIP. A file in a ZIP that is not the expected format is rejected and listed; the rest import.
- Every element no reader reads is listed as left out, once per element name and file. The maintenance agency and history describe the file, not the record: the audit log keeps the Workspace's own.

## Evidence

`tests/Feature/ContextExchangeTest.php`: an authority record and an activity exported and imported again write nothing to the audit log and give the same file; the same file without its IRI makes a new record with every name, text, date and relation, each in its direction; a ZIP dry run lists what has no place and rejects what is not EAC-CPF.

## Consequences

- An EAC file from another system without a persistent IRI is matched by its `recordId` on later imports, as the EAD import is matched by its identifiers.
- Relations to entities not in the Workspace are listed, not created. The other end must already exist under its IRI.
- `useDates` on a name is listed, not read: the Workspace keeps a name's dates on the name, which EAC-CPF import does not yet fill.
