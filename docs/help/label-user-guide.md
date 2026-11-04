---
title: Labels
category: Import and export
order: 50
staff: true
routes: labels.*
heratio: label-user-guide.md
---

# Labels

Boxes, folders and shelves need labels: a title and reference code to read, and a barcode or QR code to scan. The Workspace prints them onto label sheets.

## Printing labels

Labels are printed from a unit of description, for it alone or for every unit inside it, and from a container. Choose **Labels** on its page, then:

1. choose the **template**, the sheet the labels go on;
2. choose what each label shows: the title, the identifier, the repository;
3. choose what the **barcode** and the **QR code** hold, such as the identifier, the persistent IRI or the container's barcode;
4. say how many labels on the sheet are already used, so printing starts at the next free one;
5. **Print**.

The labels open as a page sized to the sheet, ready for the browser's print. Barcodes and QR codes are drawn by the Workspace itself; nothing is sent to an outside service.

## Templates

**Label templates** (`/labels/templates`) describes the sheets you print on: the label width and height, the rows and columns, the margins and the gap between labels, all in millimetres, and the text size. One template is the default.

## Scanning

A container's barcode, scanned with a hand scanner on the containers screen, opens that container. See [Storage](/help/article/storage-manage-user-guide). A QR code that holds the persistent IRI opens the record's public page on any phone.
