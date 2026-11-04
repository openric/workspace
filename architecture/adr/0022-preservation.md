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

## 15c: normalisation, migration, replication and PREMIS rights

Copied from Heratio's `ahg-preservation`: its normalisation rules and `NormalizationService`, its conversions, its migration pathways and plans, its format obsolescence, its replication targets and log, and its PREMIS rights.

**Normalisation.** A rule says that files of a PRONOM format, or of a MIME type, are normalised by a tool into another format, for preservation or for access. Heratio's 34 default rules are seeded: images to TIFF, PDF to PDF/A-2b, office documents to PDF, sound to WAV and video to FFV1 in Matroska for preservation; TIFF and PNG to JPEG, office documents to PDF, WAV to MP3 and Matroska to MP4 for access. A rule naming the file's PRONOM format comes before one naming its MIME type, then by priority.

What is made goes into the instantiation's OCFL object, beside the master:

- a preservation master under `files/preservation/`;
- an access copy under `files/access/`.

It is two new file uses. A preservation master is read as the master is (read-master, the download clearance, the master's embargo); an access copy as the reference copy is. Each is recorded as a conversion with its tool, the tool's version, sizes, checksums and time, and is a PREMIS normalisation event from the master. A tool that fails is a failed conversion and a failure event on the master.

An institution normalises each master as it is kept only if it chooses to; this is off by default, where Heratio always normalises. Who manages the institution can normalise the masters kept before, all at once (`workspace:normalise`) or one instantiation at a time.

What was changed from Heratio:

- Each tool runs from an argument list in its own process with a time limit; Heratio built a shell string.
- Its output is made in a folder of its own and only then kept; Heratio wrote beside the source.
- The master is never replaced.
- The tools are the same four: ImageMagick, FFmpeg, Ghostscript and LibreOffice.

**Migration.** A migration pathway carries one PRONOM format into another by a tool, with what it loses (lossless, minimal, moderate, significant), a fidelity score, whether it is recommended and whether it may run without review. A format can name the format its files are to be migrated to, and why its risk is what it is. The formats page shows each format's files, their size, its risk and the pathway recommended for it, worked out when the page is shown. Heratio keeps that in its own `preservation_format_obsolescence` table, which goes stale.

A migration plan takes the institution's files of the pathway's source format, or only those of a repository, or of a unit and what it includes. It is drawn up as a draft and approved by someone other than who drew it up. It is then run in the background, at once or from its scheduled start. Each file's migration is a conversion and a PREMIS migration event, its result a preservation master beside the master, checked for fixity after where the plan says so. A plan can be cancelled while it runs, and its failed files tried again.

**Replication.** A replication target is a folder on this server or on another reached by rsync over SSH with a key file. It is copied to on its schedule (daily, weekly or monthly) or at once, and can be compared with the object store. The copy is the OCFL storage root as it stands, so an object purged by a disposal leaves the copy at its next run too.

Two safeguards stand in front of every run:

- A target folder must be empty, not yet made, or already hold an OCFL storage root; anything else is refused, so a target set to the wrong folder is not emptied.
- The host, user, folder and key file are checked against narrow patterns before they reach rsync.

Each file copied is a PREMIS replication event, and each run is kept with its counts, bytes and what it changed or found different.

**PREMIS rights.** The rights in force on the unit and its instantiations, as every other export gives them, become PREMIS rights statements:

- the basis: copyright, licence, statute, or other for donor and policy;
- its copyright status, jurisdiction and determination date, its licence and terms, or its statute citation;
- its applicable dates;
- each act granted with its restriction and term;
- the object it bears on;
- its holder, as an agent.

The export validates against the PREMIS 3.0 schema with them.

### Not carried in 15c

- Heratio's `preservation_policy` and `preservation_workflow_schedule` and runs:
  - fixity is on its own schedules since 12a;
  - replication and migration plans carry their own schedules;
  - format identification and virus scanning run as each file is kept.
- Heratio's S3, Azure and Google Cloud replication targets: each needs its SDK. A mounted share or another server by rsync covers a second copy now.
- Heratio's `migration_command`: a pathway's command is built from its tool and options, never kept as text to run.
- Heratio's `quality_score` on a conversion: nothing measures it.
- Heratio's `max_concurrent` on a plan: one worker runs a plan's files one at a time.
- Importing PREMIS rights XML (Heratio's `source_xml`): rights come in through the rights form and the imports that already carry them.
- OAIS packages, BagIt, SWORD and Archivematica: 15d.

### Evidence (15c)

- `tests/Feature/PreservationPlanningTest.php`:
  - a PNG master normalised on ingest into a TIFF preservation master and a JPEG access copy, in the instantiation's OCFL object, the viewing copies made as before;
  - the conversion with its tool and version, the normalisation event from the master, not made twice, and readable with read-master;
  - the preservation master's source and its outcome event in PREMIS;
  - a tool that fails as a failed conversion and a failure event;
  - rules, pathways and targets set by operators only, a rule naming neither a format nor a type refused;
  - a migration plan refused approval by who drew it up, approved by a second administrator, run, its file migrated to TIFF as a migration event and checked for fixity;
  - the object store replicated to a folder as a valid OCFL storage root, each file a replication event;
  - a changed copy found by comparison, and a purge carried to the copy;
  - a folder holding other files refused and left as it was;
  - a folder inside the object store, and a host a shell would read, refused;
  - the unit's copyright and statute rights as PREMIS rights statements, valid where `PREMIS_XSD` is set.

## 15d: OAIS packages, SWORD and Archivematica

**OAIS information packages**, after Heratio's `BagItService` and `OaisLifecycleService`. A unit of description, with what it includes where chosen, is put together as a BagIt 1.0 bag (RFC 8493) and serialised in one ZIP or TAR file. The file is kept in an OCFL object of its own, named `<base IRI>/package/<id>`.

What each kind holds:

- a SIP: the masters;
- an AIP: the masters and preservation masters, with the unit's RiC-O;
- a DIP: the access copies, or else the reference copies.

Each holds the unit's METS and PREMIS under `data/metadata`. A DIP carries what a reader sees, never drafts. A file goes in only where the account that builds the package may receive it.

The bag has its manifest and tag manifest (SHA-256 or SHA-512) and a bag-info with:

- Source-Organization, the contact, and External-Identifier (the unit's IRI);
- Bagging-Date, Bag-Size and Payload-Oxum;
- Bag-Group-Identifier (the package it was made from) and the Workspace as Bag-Software-Agent.

A SIP is carried on into an AIP and an AIP into a DIP, each naming the one it came from, as Heratio's lineage does. A package is validated again against its kept SHA-256, its manifests and its Payload-Oxum. Every build and validation is a PREMIS event of the package.

A package can be downloaded, which marks it exported, and removed, which purges its OCFL object. A destruction purges the unit's packages with its files, since they hold copies of them. The test checks a built bag with the Library of Congress's bagit-python where `BAGIT` names it.

**SWORD 1.3 deposit**, as AtoM's `qtSwordPlugin` answers it, so that Archivematica's "Upload DIP to AtoM" can deposit a DIP here. Heratio has no twin; it is written fresh against the DIP ingest.

What it offers:

- a service document listing the top-level units, or a unit's parts, as collections;
- a deposit to a unit of a `METSArchivematicaDIP` package, as a ZIP body or named by Content-Location in the one folder the installation allows;
- the deposit taken in in the background, and answered 202 with an Atom entry.

What it refuses, with SWORD's error documents:

- another packaging or content type;
- a Content-MD5 that does not match;
- a package larger than an upload may be;
- a Content-Location outside the folder.

An account signs in with HTTP Basic as its e-mail address and an API key of its own with the write scope, never its password. Two-factor sign-in is therefore not passed by, where AtoM takes the password.

**Archivematica**, after Heratio's `ahg-archivematica`, against Archivematica's documented Dashboard and Storage Service APIs (Dr Johan Pieterse, 4 November 2026: "Build last, against its API (Recommended)"). Tested with a faked Archivematica.

- **Settings.** The installation's operators set them: the Dashboard and Storage Service with their users and API keys (encrypted), the pipeline, the transfer source location UUID and folder, the staging folder, the transfer type, and the SWORD deposit folder.
- **Sending a unit.** Its masters, with what it includes, are staged under `objects/`. A `metadata/metadata.csv` gives the unit's IRI as `dc.identifier`, so the DIP that comes back finds it. The transfer is started, approved once Archivematica lists it, and followed every five minutes through transfer and ingest to its AIP.
- **Fetching DIPs.** Where switched on, the DIPs the Storage Service holds are fetched each hour, each once.
- **Taking a DIP in.** A fetched DIP and a SWORD deposit go through one ingest. Its METS is read with Heratio's METS parser, copied unchanged.
- **Finding the unit.** It is the unit the deposit names, else the one the AIP's transfer was sent from, else the one whose IRI, or whose identifier alone, the DIP's `dc:identifier` gives.
- **The files.** Each access file is kept through the same upload every file takes, as an instantiation of the unit. What the AIP's PREMIS said of it (original name, PUID, digest) is kept as a PREMIS metadata extraction event.

What was changed from Heratio and AtoM:

- No processing configuration is shipped. Heratio's `processingMCP.xml` is Archivematica's own, whose authorship is not Heratio's, so it is not copied. An operator's `processingMCP.xml` in the staging folder is used where there is one, else Archivematica's default.
- A DIP's files come in as instantiations of the unit, not as new descriptions as AtoM makes them.
- An archive is unpacked only where no name in it climbs out of its folder.
- A DIP that matches no unit belongs to no institution, and only operators see it.

Two encrypted fields that key rotation (12a) did not re-encrypt are added to it: single sign-on client secrets and two-factor secrets, both from 12b. A test now fails when an encrypted field is left out.

### Not carried in 15d

- Heratio's `PackageCleanupCommand`: packages are kept until removed, in their OCFL objects; nothing is built in a folder that lingers.
- Heratio's slug match strategy for DIPs: the Workspace has no slugs; the IRI takes its place.
- AtoM's SWORD mediation (X-On-Behalf-Of), no-op and verbose modes, which AtoM leaves undone too.
- Archivematica's reingest and the AIP's own download: the AIP stays in Archivematica's Storage Service, linked by its UUID.

### Evidence (15d)

- `tests/Feature/PackagesAndArchivematicaTest.php`:
  - packages:
    - a fonds packaged as a SIP with its item's master, METS and PREMIS, its bag valid by the Workspace and by bagit-python;
    - carried on into an AIP with RiC-O and a DIP with the reference copy and not the master, and no package made from a DIP;
    - a changed package found;
    - downloaded and marked exported, and removed with its OCFL object;
  - SWORD:
    - refused without a key, with the password, or with a read-only key;
    - the service document listing the units as collections;
    - deposits refused for another packaging, a wrong MD5, or a location outside the folder;
    - a DIP deposited as a ZIP and by Content-Location, each taken in as an instantiation of the unit with the AIP's PREMIS kept;
  - Archivematica:
    - a fonds sent to a faked Archivematica: its masters staged with its IRI as `dc.identifier`, the paths sent as Archivematica reads them with its API key;
    - followed through transfer and ingest to its AIP;
    - its DIP fetched from the Storage Service, matched by the AIP's UUID, taken in once.
- `tests/Unit/EncryptedFieldsTest.php`: every field cast as encrypted is one key rotation re-encrypts.
