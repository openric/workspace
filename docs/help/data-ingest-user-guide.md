---
title: Importing
category: Import and export
order: 10
routes: imports.*
heratio: data-ingest-user-guide.md
---

# Importing

Most institutions arrive at the Workspace with descriptions already written, in AtoM, in spreadsheets or in EAD finding aids. The import takes them in, and shows you what it will do before it does anything.

The screens are under **Import and export**, **Imports** (`/imports`).

## What it reads

| Format | Becomes |
|---|---|
| CSV in AtoM's ISAD(G) columns | Archival descriptions |
| EAD 2002 or EAD3 | Archival descriptions, with the hierarchy of the finding aid |
| CSV in AtoM's ISAAR(CPF) columns | Authority records |
| CSV in AtoM's ISDIAH columns | Repositories |
| EAC-CPF, one file or a ZIP of files | Authority records |
| EAC-F, one file or a ZIP of files | Activities |
| RiC-O in JSON-LD, Turtle or RDF/XML | Records, with the authority records and repositories the file describes |

A CSV import also reads an Excel or OpenDocument spreadsheet, as its first sheet, and a ZIP holding one CSV or spreadsheet together with the files its rows name in `digitalObjectPath`, which come in as the records' digital copies. A file may be up to 20 MB, in UTF-8.

Importing AtoM data as a whole? Import the repositories first, then the authority records, then the archival descriptions, each with the same source, so the descriptions find their holders and creators.

## The steps

1. **New import.** Choose the format and the file, the language it is written in, and its **source**, such as *AtoM, Riverside, 2026*. Importing again from the same source updates what it made rather than making it twice.
2. **Choices.** For archival descriptions, choose the repository that holds the top-level units, what to do with units that have no level of description (AtoM allows that; the Workspace does not, because the level decides whether a unit is a record set, a record or a record part), whether to classify what it creates, and whether to create subjects, places, occupations and documentary forms not yet in the vocabularies.
3. **Mapping.** Column headings the import does not know are shown for you to map to a field, or to leave out.
4. **Dry run.** Uploading always makes a dry run: the import is made and rolled back, and its report says what each entry would become and every field that cannot be taken as given, row by row. Nothing is written.
5. **Import.** Once you have read the report, choose **Import**. Every entry is made as a draft.

Every import stays listed with its report, run or not.

## Matching what is there

An import does not make a second copy of what already exists. Units are matched by their identifier under the same parent; authority records and repositories by their authorised name. A match is updated rather than duplicated.

## Undoing an import

**Undo** deletes what an import made, children before their parents, each as any entity is deleted, so its IRI is kept and answers as deprecated. An entry anyone has changed since, or that holds something the import did not make, is kept and named in the report, so undoing never loses later work. What the import only updated is not put back; the audit log keeps what it was. Agents, places and terms the import made for access points stay.

## Rows in a grid

**Enter rows in a grid** is for a handful of units typed in by hand: a spreadsheet-like page with a row a unit, checked and imported the same way.

## The package it came in

**Bag** downloads the import as a BagIt 1.0 bag: the file as it was uploaded, the master files the import kept, and a list of every entry in the file with what became of it and its persistent IRI, all with their SHA-256 checksums. It is the form an OAIS submission package is usually exchanged in.
