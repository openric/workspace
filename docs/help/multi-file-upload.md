---
title: Uploading files
category: Digital objects and viewers
order: 10
routes: instantiations.*, files.*
heratio: multi-file-upload.md
---

# Uploading files

A scan, a recording or a born-digital file is kept as an instantiation of the record it is a copy of: a `rico:Instantiation`, with its files. One record can have several: the paper original, a microfilm, a master scan and a later one. Each is described on its own, and each has its own persistent IRI.

## Uploading a file

On a unit of description's page, open **Instantiations: digital files and other carriers** and use **Upload a file, or add another carrier**:

1. Choose the file. The limit is set by the institution's installation, 60 MB unless it is raised.
2. Give it a label and the language of that label.
3. Optionally give the **SHA-256 you expect**, from the scanner or the depositor. A file that does not match is refused, so damage on the way is caught.
4. Choose its **availability**: online, on request, withdrawn or lost.
5. Save.

As the file arrives, the Workspace:

- takes its SHA-256 and keeps the file exactly as received, as the master, in the object store;
- scans it for viruses, where a scanner is set up;
- identifies its format against PRONOM;
- for an image or a PDF, makes a reference copy and a thumbnail to show it by; for audio and video, a viewing copy;
- reads its technical metadata, such as its dimensions, duration or EXIF, and proposes for review what a description could use;
- records each of these as a PREMIS event.

Leave the file empty to record a carrier with no file, such as the paper original, with its carrier type.

## Many files at once

To give each file a unit of its own, as a series of page images or a run of photographs, use **Import units into it** on the record set, or set up a watched folder that takes files in as they arrive. See [Importing](/help/article/data-ingest-user-guide) and [Scanning and watched folders](/help/article/scan-user-guide).

## Describing an instantiation

**Describe instantiation** records what is known of the copy: its carrier type, extent, dimensions, physical characteristics and notes, each with its RiC term. Fill in what you know; empty fields are left out.

The files themselves never change. A new scan is a new instantiation, not a replacement, so the record of what was received stays intact.

## Viewing copies

The reference copy and thumbnail are made from the master. **Make the viewing copies again** remakes them, for instance after a watermark is set. **Give your own copy** replaces the reference copy or thumbnail with an image of your choosing, such as a better crop; remaking the copies puts the made one back.

## Who sees the files

The public sees a file only if the record and the instantiation are published, the instantiation is online, and the rights in force allow it. A file kept back can still be asked for: **Ask for access to these files** sends an access request, and an approved request opens them to the person who asked. See [Access requests](/help/article/access-requests-user-guide).
