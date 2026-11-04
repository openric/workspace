---
title: A file and its copies
category: Digital objects and viewers
order: 20
routes: files.show
heratio: edit-digital-object.md
---

# A file and its copies

Each file of an instantiation has a use:

| Use | What it is |
|---|---|
| Master | The file as it was received, never changed |
| Reference copy | A copy made for viewing in the page, for images and PDFs |
| Thumbnail | A small copy for lists and search results |
| Preservation master | A copy in a format chosen for keeping, made by normalisation |
| Access copy | A copy in a format chosen for use, made by normalisation |

Open a file from its instantiation to see what is known of it: its size, SHA-256, format and PRONOM identifier, its technical metadata as the file gives it (read with exiftool), and its preservation history.

## The preservation history

Every action on a file is a PREMIS event, with its date, outcome and agent, the person or the software and its version: its ingestion, the copies made from it, its message digest, its virus check, its format identification, each fixity check and, if it comes to it, its deletion. The events are kept after the file is gone. **Download the PREMIS 3.0 preservation metadata of the unit** gives them all as PREMIS XML.

**Check fixity now** takes the file's SHA-256 again and compares it with the one recorded when it arrived. A file whose checksum has changed is flagged as failed, and the failure is listed for the institution to act on. Fixity is also checked on a schedule; see [Fixity](/help/article/dp-fixity-and-checksums).

## Normalising

**Normalise the master by the rules** makes a preservation master and an access copy from the master, by the institution's normalisation rules, each recorded as a PREMIS event. See [Formats and normalisation](/help/article/dp-format-identification).

## Replacing and removing

A master is never replaced: a new version of a file is a new instantiation. A viewing copy can be remade or replaced, as [Uploading files](/help/article/multi-file-upload) explains. Removing an instantiation removes its files from view; the object store keeps every version until a disposal, cleared under the records management rules, purges it.
