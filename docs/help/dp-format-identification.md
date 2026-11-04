---
title: Formats, normalisation and migration
category: Preservation
order: 30
staff: true
routes: formats.*
heratio: dp-format-identification.md
---

# Formats, normalisation and migration

A file is only useful while something can read it. Knowing exactly what format each file is in, and which formats are at risk, lets an archive convert files before they become unreadable.

## Identifying formats

Each file is identified against PRONOM, the UK National Archives' format registry, by Siegfried, as it arrives. Its PRONOM identifier (PUID), such as `fmt/353` for TIFF, is kept with it, and the identification is a PREMIS event. **Identify them now** identifies any file not yet identified.

## The format register

**File formats** (`/formats`) lists every format the institution holds, with how many files and how much space. For each, record:

- its **risk**, and why;
- its **action**: keep as it is, normalise, or migrate;
- whether it is a **preservation format**, one chosen for keeping;
- its **migration target**, the format to migrate it to.

## Normalisation

Normalisation makes, from a master, a **preservation master** in a format chosen for keeping and an **access copy** in a format chosen for use, beside the master, which is never changed.

**Normalisation rules** (under **Preservation**) say, for each format, what to make from it and with which tool: ImageMagick, Ghostscript, LibreOffice or FFmpeg. An institution chooses whether each master is normalised by these rules when it is kept, or later with **Normalise them now**. Each conversion is a PREMIS normalisation event, and its result is listed under **Conversions**.

## Migration

Migration converts files from a format at risk to a safer one, in a planned way.

- A **migration pathway** says how one format becomes another: the tool, its quality and fidelity, and whether it is automated. One pathway per format can be marked as recommended.
- A **migration plan** follows a pathway for the files of a format. It is drawn up as a draft, approved by a second person, and then run. Each file migrated is a PREMIS migration event and is checked for fixity afterwards.

## In exports

The PUID and format name of each file go out in the PREMIS export and in METS.
