---
title: Scanning and watched folders
category: Import and export
order: 40
staff: true
routes: intake.*
heratio: scan-user-guide.md
---

# Scanning and watched folders

Digitisation produces files steadily, from a scanning station or a camera stand, and nobody wants to upload each by hand. A watched folder takes them in as they arrive; the scan API lets a capture station send them itself.

The screen is under **Import and export**, **Intake** (`/intake`). It needs the right to administer the institution.

## Watched folders

A watched folder is a folder under the installation's intake root (`WORKSPACE_INTAKE_ROOT`), which an operator sets up on the server. Without one, no folder can be watched yet, and the screen says so.

**Add a watched folder**, giving:

- its name, and the folder under the intake root;
- the repository that holds what it makes, and the level of description;
- where the records go: inside a record set, by its persistent IRI;
- the language of what it describes.

Every minute the Workspace looks in each watched folder that is switched on. What it finds there:

- **a single file** becomes a unit of description of its own, with the file as its master, named from the file;
- **a BagIt bag** is checked against its manifests before anything in it is taken in;
- **a table**, a CSV or spreadsheet of archival descriptions, authority records or repositories, is imported at once if its dry run refuses nothing, with the mapping saved for it; otherwise it waits for someone to read its report.

**Look in it now** does not wait for the next round. Everything taken in is listed with when, from which file, and what became of it: imported, waiting for review, record made, or failed, with the reason.

## The scan API

A capture station can send its files straight to the Workspace (`POST /api/ric/v1/intake`) with an API key that has the write scope. Each file becomes a record as a watched folder's would, and its answer says what was made.

## What happens to each file

A file taken in is treated as any upload: its SHA-256 taken, scanned for viruses, its format identified, its viewing copies made and each step recorded as a PREMIS event. See [Uploading files](/help/article/multi-file-upload).
