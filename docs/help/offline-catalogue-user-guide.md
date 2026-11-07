---
title: Offline catalogues
category: Import and export
order: 35
routes: offline-catalogues.*
heratio: portable-export-user-guide.md
---

# Offline catalogues

An offline catalogue is your catalogue, or part of it, in one ZIP file that opens with a double-click. No internet, server or installation is needed: unzip it on a USB stick, a disc or a reading-room computer and open `index.html` in any recent browser. It comes from Heratio's portable export.

Making one needs the permission **Make offline catalogues**, which administrators and editors hold. Open **Import and export**, then **Offline catalogues**.

## What it holds

Choose one of these:

- **Every description**.
- **One repository** and its descriptions.
- **One unit and everything inside it**. Start from **Offline catalogue of this unit and everything inside it** on the unit's export page.
- **The descriptions on the clipboard**, as they are when you make it.

With them go the authority records of their creators, their repositories and, if you tick **Include files**, their thumbnails and reference copies.

## What it is for

- **Read only** is for handing out: a reading room, a researcher, a partner. It is made as the public sees the catalogue, with published descriptions only and the files the public may receive. It never holds a master.
- **Editable** is the same, and a researcher may also write notes, sources, suggested corrections and files about each record while offline. The viewer saves them as `researcher-exchange.json`. Under **Research**, **Submissions**, the researcher imports that file and it becomes a draft submission of theirs: each note, source and suggestion a note on its record, each file a described file. Nothing changes in the catalogue until an archivist approves the submission.
- **Archive** is for your own staff. It is made as you read the catalogue: drafts too, where you may see them, and masters if you tick **Include masters** and may download them. An archive is never given a link.

A **language** may be given (a BCP 47 tag such as `en` or `af`). A value not written in it is given in the language it was first written in.

The package is made in the background. The list shows when it is ready, how many descriptions, authority records and files it holds, how many things it left out, its size and its SHA-256.

## Inside the package

| File | What it is |
| --- | --- |
| `index.html` | The viewer: a list to search and browse, each description with its files, creators and place in the hierarchy |
| `data/catalogue.jsonld` | Every description, authority record and repository in RiC-O 1.1, with its persistent IRI, as the Workspace's API gives it |
| `data/catalogue.json` | The same descriptions in a simple form for other programs |
| `data/manifest.json` | The scope, mode, counts and date |
| `data/disclosure-summary.json` | What was left out, and why |
| `left-out.csv` | What of each description the package does not carry |
| `SHA256SUMS`, `data/fixity.json` | The SHA-256 of every file |
| `verify.html`, `verify.sh` | Checks that nothing was changed |
| `README.txt`, `USER-MANUAL.txt` | How to open it, and in an editable package how to work offline |

: The files in an offline catalogue

The viewer reads its data as scripts rather than fetching it, because browsers refuse to fetch files for a page opened from disk. That is why it opens with a double-click, from anywhere.

## Checking that nothing was changed

Open `verify.html` and choose the unzipped package folder. Every file is read on that computer, its SHA-256 worked out and compared with the one recorded when the package was made. On Linux or macOS, `sh verify.sh` in the folder does the same.

## Taking a package back in

An archive's `data/catalogue.jsonld` is RiC-O, so the RiC-O import reads it, into this institution or another. Run it as a dry run first to see what it would create.

## Links

A read-only or editable package can be given a link for someone without an account. It may open until a date, or for a number of downloads. The link is shown once: only the SHA-256 of its token is kept, so a lost link cannot be shown again. Revoke it and make another. Each link shows how often it was used.

## Deleting

**Delete** removes the package from the object store and its links stop opening. A package being made cannot be deleted until it is done.
