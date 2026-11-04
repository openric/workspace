---
title: Viewers and IIIF
category: Digital objects and viewers
order: 40
routes: iiif-collections.*, iiif-workspaces.*, iiif-validation.*, discover.iiif
heratio: iiif-integration-user-guide.md
---

# Viewers and IIIF

Each file is shown in the viewer that suits it, both on the staff pages and in the public catalogue:

| Kind of file | Viewer |
|---|---|
| Images | Deep zoom (OpenSeadragon), with a scale bar and magnifier; or Mirador, side by side with others |
| PDF | pdf.js, page by page, with search |
| Audio | A waveform player, with its caption tracks |
| Video | A media player, with its caption tracks |
| 3D models (GLB) | A model viewer you can turn and zoom |

The viewers are served by the Workspace itself, so a page calls no third party to show a file.

## IIIF

The International Image Interoperability Framework lets any IIIF viewer anywhere show your images and recordings. Every published unit with files the public may see has:

- a **IIIF Presentation 3 manifest** (`/iiif/records/{id}/manifest`): its images, audio and video in order, with its descriptive metadata and its rights;
- a **collection** of the manifests of the units inside it, for a record set;
- **content search** (`/iiif/records/{id}/search`, IIIF Content Search 2.0) over its published transcripts, with autocomplete, so Mirador's search panel finds words in the text of its pages.

The catalogue as a whole has a top-level collection (`/iiif/collection`) and a **Change Discovery** feed (`/iiif/discovery/changes`) that tells aggregators what was published, changed or withdrawn. **Content State** links open a viewer at a given record and view, to share or cite.

Files kept back by rights or an embargo are protected by **IIIF Authorization 2**: a viewer is told the file needs access and how to get it, and shows it once the visitor has access.

## Curated collections

**IIIF collections** (`/iiif-collections`) are groups of manifests you put together: your own records, or manifests from other institutions by their address, in the order you choose. Each is published as a IIIF collection that any viewer can load, for an exhibition, a teaching set or a theme.

## Your viewer layouts

**My viewer layouts** (`/iiif-workspaces`) keeps the Mirador workspaces you set up, the windows, the records in them and how they are arranged, so you can open the same comparison again later.

## Checking manifests

**IIIF validation** (`/iiif-validation`) checks a record's manifest, or every one, against the IIIF Presentation 3 rules, and lists what fails, so a harvester or a viewer never meets a broken one.
