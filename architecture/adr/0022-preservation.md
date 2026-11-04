# ADR 0022: Preservation, PREMIS events first, OCFL as the one store

- Status: accepted
- Date: 2026-11-04
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 15 copies Heratio's preservation packages:

- `ahg-preservation`, about 10,000 lines: events, checksums, PRONOM format identification, format policies, normalisation and migration plans, replication, backup verification, OAIS packages and PREMIS rights;
- `ahg-ocfl`, the OCFL storage layout;
- `ahg-archivematica`, transfer and status.

It also adds SWORD deposit, after AtoM's `qtSwordPlugin`. The Workspace already kept every file under its SHA-256, scanned it for viruses, read its embedded metadata with exiftool, made viewing copies, and checked fixity on a schedule (phase 12a). None of that was recorded as preservation events, and file formats were known only by MIME type.

On 4 November 2026 Dr Johan Pieterse chose:

- **OCFL is the one object store.** It replaces the checksum store outright, with no period of mirroring: the live instance holds no real records yet, and a mirror was only a safeguard for real data. OCFL objects describe themselves (an inventory, checksums, versions), so they can be read without the Workspace.
- **Archivematica is built last,** against its documented API, tested with a faked Archivematica, and tried against a real one when one exists.

Phase 15 is split, all of `ahg-preservation` among the parts, as with `ahg-research` in phase 19:

- 15a: PREMIS events, PRONOM formats and the PREMIS export;
- 15b: OCFL as the one store;
- 15c: format policies, normalisation and migration plans for obsolete formats, replication, and PREMIS rights;
- 15d: OAIS packages, SWORD deposit and Archivematica.

Backup verification stays with phase 3.

## Decision (15a: PREMIS events and PRONOM formats)

**Every action on a file is a PREMIS 3 event,** after Heratio's `preservation_event`: its type, when it happened, a detail, its outcome (success, failure, warning) with a detail, and its agent, either a person or the software and its version.

- **The event types** are a vocabulary, each linked by `skos:exactMatch` to the Library of Congress preservation vocabulary (id.loc.gov/vocabulary/preservation/eventType). Each code was checked against that vocabulary itself, and several were not what memory suggested: `ing` is ingestion, `der` is decryption, `val` is validation.
- **What is recorded where:**
  - A file's own model events record its ingestion (a master, by who uploaded it), or its creation (a viewing copy, from the master as its source); then its message digest calculation and its virus check, by the scanner; and its deletion. Every path that keeps a file is covered: upload, intake, imports, copies.
  - Fixity checks, format identification (by Siegfried) and metadata extraction (by exiftool) record their own events.
- **Events outlive their file.** No foreign key holds an event to its file, and each keeps the file's name, as a fixity check does.

**Formats are identified against PRONOM by Siegfried,** where Heratio matched a short list of magic bytes it kept itself.

- Siegfried reads PRONOM's own DROID and container signature files; release 1.11.1 with signatures V118 is on the host.
- Identification runs in the background when a file is kept, and `workspace:formats:identify` identifies files kept before.
- A format is one row for the installation, with its PUID, name, version, MIME type and class. Its risk (low, medium, high, critical) and action (none, monitor, migrate, normalise) are vocabulary rows, as in Heratio, and are set by the installation's operators, since a format is the same for every institution.
- Each institution sees its own files by format. A file not identified keeps Siegfried's warning.

**The PREMIS 3.0 export** is an export format of a unit, after Heratio's `PremisXmlSerializer`:

- the unit as an intellectual entity;
- each instantiation the reader may read as a representation;
- each file with its SHA-256, size, format (PRONOM registry key) and original name;
- a viewing copy's derivation from its master;
- every event, each event and agent type with its Library of Congress term;
- the agents, once each.

It validates against the PREMIS 3.0 schema. The schema states no licence, so it is not kept in the repository; the test validates against it where `PREMIS_XSD` names a copy.

### Not carried in 15a

- PREMIS rights statements (Heratio's `ahg_premis_rights`): PREMIS copyright information needs a status and a jurisdiction the Workspace does not keep in that form. They come in 15c.
- Heratio's checksums in MD5, SHA-1 and SHA-512: the store keeps SHA-256, which every file came in with.
- Heratio's format policies, conversions, migration plans, replication and OAIS packages: these come in 15c and 15d.

### Evidence (15a)

- `tests/Feature/PreservationEventsTest.php`:
  - an upload kept as ingestion by its person, a copy as a creation from the master, the digest as an event;
  - the master identified as PNG against PRONOM where Siegfried is installed;
  - a fixity check as an event;
  - the PREMIS export's entity, representation, files, digest, derivation and events, valid PREMIS 3.0 where `PREMIS_XSD` is set;
  - a deletion kept after the file is gone;
  - the formats page, and a format's risk and action set by an operator.

## 15b: OCFL as the one object store

Dr Johan Pieterse, 4 November 2026: "this is not live data. so one store." The checksum layout the Workspace kept files in until 0.0.114 (`<institution>/<aa>/<bb>/<sha256>`) is replaced outright by an OCFL 1.1 storage root, copied from Heratio's `ahg-ocfl` and corrected. There is no period of keeping both.

**The storage root** declares itself (`0=ocfl_1.1`) and its layout in `ocfl_layout.json`. The layout is OCFL's published extension 0004, hashed n-tuple storage: the SHA-256 of the object's id, three tuples of three characters, then the whole digest. Any OCFL tool can find an object from its id without the Workspace.

**One object per instantiation**, named by the instantiation's IRI. Its master is at `files/master/<the file's name>`, its copies at `files/reference/...` and `files/thumbnail/...`, its caption tracks at `captions/<language>-<kind>.vtt`. Any other file the Workspace keeps (an agreement, a permit, an email, a transfer package) is an object of its own, named `<base IRI>/file/<sha256>`. A row's `storage_path` is the content file in the object, so reading a file is unchanged.

**Each change is a new version holding the whole object**: the head's files, less those removed, with those added or replaced. Content already in the object is not written twice. A version is written to a staging folder and put in place whole, the object's inventory and its SHA-512 sidecar after it, and writes to one object wait for each other. Each content file's SHA-256 is in the inventory's fixity block, so the fixity checks of phase 12a go on reading the digest every file came in with.

What was changed from Heratio:

- Heratio's layout ended in the url-encoded id and named no published extension; this one is extension 0004.
- Heratio's new version held only the files written in it, so each version dropped the earlier files; here each version is the whole object.
- Heratio had no lock and no staging, so a failed write could leave half a version.
- Heratio had no purge. A disposal must erase, and OCFL keeps every version, so a destruction now removes the instantiation's object, every version of it, unless a record still in the Workspace uses it.

**Moving what was kept.** `workspace:ocfl:migrate` moves each instantiation's files and captions into the first version of its object and every other kept file into its own object. A file is moved only when its checksum is still the one recorded. The old layout is removed once every object written verifies and no row names an old path. The file guard on `digital_files` lets a file's place change in that command's transaction only, which says so with `SET LOCAL workspace.moving_files`; nothing else may change it. The release of 0.0.115 runs it on live.

`workspace:ocfl:verify` checks the storage root and every object in it: the declaration, the inventory and its sidecar, each version's inventory, and every content file against its digest.

### Not carried in 15b

- Heratio's `ocfl_object` table: the inventory is the record of an object, and the Workspace's rows point into it; a second copy of the inventory in the database would only drift.
- Heratio's mirror to a second storage root: replication comes in 15c.

### Evidence (15b)

- `tests/Feature/OcflStoreTest.php`:
  - the storage root's declaration and layout, the object at its extension 0004 path, every file's content where its row says;
  - the master and its copies in the head version under their logical paths, a file's name without its folders;
  - a copy given again as a new version that still holds the master;
  - every object verifying, and a changed content file found;
  - a purged object gone with its folders;
  - files put back in the old layout moved into OCFL, the old layout removed, and the command run again moving nothing.
- `tests/Feature/RetentionTest.php`: a destruction purges the instantiation's object, and a twin record keeps its own.
