---
title: Information packages
category: Preservation
order: 40
staff: true
routes: packages.*
heratio: dp-oais-packages.md
---

# Information packages

The OAIS reference model speaks of three kinds of information package: what an archive receives, the **Submission Information Package** (SIP); what it keeps, the **Archival Information Package** (AIP); and what it gives out, the **Dissemination Information Package** (DIP). The Workspace makes all three from a unit of description.

## Making one

On a unit's page, **Information packages**: choose the kind and make it. The package is a BagIt 1.0 bag holding:

- the unit's files, the masters for a SIP or AIP and the files the public may receive for a DIP;
- its METS, describing the unit and every unit inside it, with each file's SHA-256;
- its PREMIS, with every file's format and every event done to it;
- BagIt's manifests of SHA-256 checksums for every file in it.

A package is carried on: an AIP made from a SIP, and a DIP from an AIP, keep the chain.

## Status

A package is building, then complete, validated (its bag checked against its manifests again), exported, or in error with the reason. **Validate** checks it again at any time.

## Keeping and downloading

Each package is kept in an OCFL object of its own, so it is preserved like everything else. **Download** gives the bag as a ZIP; removing a package purges its object.

## SWORD

The Workspace answers SWORD 1.3 deposits as AtoM does, so Archivematica, or another system, can upload a DIP into a unit, signed in with an API key that has the write scope. The service document is at `/sword/servicedocument`.
