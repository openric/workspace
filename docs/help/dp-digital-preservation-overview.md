---
title: Digital preservation
category: Preservation
order: 10
staff: true
routes: preservation.*
heratio: dp-digital-preservation-overview.md
---

# Digital preservation

Digital preservation keeps files usable, authentic and trustworthy for decades, long after the software, formats and disks they came on have gone. The Workspace does it by the standards archives use: the OAIS reference model (ISO 14721), PREMIS 3 preservation metadata, OCFL 1.1 for storage, BagIt and METS for packages, and PRONOM for formats.

## The ideas

- **Fixity.** Each file's SHA-256 is taken as it arrives and checked again later. A file that no longer matches has been damaged or altered, and is flagged. See [Fixity](/help/article/dp-fixity-and-checksums).
- **Formats.** Each file's format is identified against the PRONOM registry, so a format at risk of becoming unreadable can be migrated in time. See [Formats, normalisation and migration](/help/article/dp-format-identification).
- **Preservation metadata.** Every action on a file is a PREMIS event, with its date, outcome and agent, kept after the file is gone. See [PREMIS and METS](/help/article/dp-preservation-metadata-premis-mets).
- **The object store.** Every file is kept in an OCFL object, versioned, with its checksums, readable without the Workspace. See [The object store](/help/article/ocfl-storage).
- **Copies elsewhere.** The store can be replicated to another disk or server and compared with it. See the same guide.
- **Packages.** A unit can be made a SIP, an AIP or a DIP, as a BagIt bag with its METS and PREMIS. See [Information packages](/help/article/dp-oais-packages).
- **Archivematica.** Units can be sent to Archivematica and their AIPs and DIPs taken back. See [Archivematica](/help/article/archivematica-integration).

## Where it lives

| What | Where |
|---|---|
| Normalisation rules, migration pathways and plans, replication | **Admin**, **Preservation** (`/preservation`) |
| Fixity schedules, runs and failures | **Admin**, **Operations**, **Fixity** (`/fixity`) |
| File formats, their risk and their action | **Admin**, **File formats** (`/formats`) |
| A file's checksum, format and events | the file's own page, from its instantiation |
| A unit's packages | **Information packages**, on the unit's page |
| Archivematica | **Admin**, **Archivematica** (`/archivematica`) |

## The tools it uses

Formats are identified by Siegfried; viruses are found by ClamAV; images, PDFs, audio and video are converted by ImageMagick, Ghostscript, LibreOffice and FFmpeg. **Preservation** lists which of these are installed on the server. Every event names the tool and its version, so what was done can always be traced.
