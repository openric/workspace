---
title: Fixity
category: Preservation
order: 20
staff: true
routes: fixity.*
heratio: dp-fixity-and-checksums.md
---

# Fixity

Fixity is the assurance that a file has not changed by a single bit since it arrived. Disks decay silently, copies go wrong, and people make mistakes; checking fixity regularly is how an archive knows its files are still the files it received.

## How it works

When a file arrives, its SHA-256 is taken and kept with it, and the file is kept in its OCFL object with the same checksum. A fixity check takes the SHA-256 again and compares:

- the same: the file is intact;
- different, or the file is missing: it is a **failure**, flagged for someone to act on.

Every check is a PREMIS fixity check event, with its outcome.

## Checking a file now

On a file's page, **Check fixity now**.

## Schedules

**Fixity** (`/fixity`) holds the schedules that check files by themselves. Each has:

- how often it runs: daily, weekly or monthly;
- what it covers: every file, a repository, or a record and everything inside it;
- how many files at most in one run, and for how long, so a large store is checked a part at a time: the files unchecked longest go first, and a run that reaches its limit is left partial for the next.

Each run is listed with what started it (the scheduler, a request or a command), when it ran, how many files it checked and how many failed; open one to see each file.

## Failures

**Failures** (`/fixity/failures`) lists every file that failed and has not been dealt with. Open one to see when it failed and against which checksum, and move it on as it is dealt with: open, acknowledged, investigating, then resolved or ignored, with a note. A failure stays listed until it is resolved or ignored.

Where the store is replicated, the copy can be compared to find a good one; see [The object store](/help/article/ocfl-storage).

## The whole store

`php artisan workspace:ocfl:verify` checks every OCFL object in the store against its inventory, its versions and its checksums, and says how many objects it checked and how many problems it found. Every release runs it after deploying.
