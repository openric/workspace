---
title: The audit log
category: Records management and workflow
order: 40
staff: true
routes: audit, history
heratio: audit-trail-user-guide.md
---

# The audit log

Every change in the Workspace is written to the audit log: what was changed, from what to what, by whom, from where and when. So is every sign-in. The log cannot be edited or deleted, by anyone, through the Workspace.

## Reading it

**Audit log** (`/audit`, for those who may view it) lists every change and sign-in in the institution, newest first, with the action, the record, who did it (or *the system*, for a scheduled job), their address, and the time in UTC.

A record's own **History** shows its changes alone, and **Versions** on a unit of description shows how it read at each one.

## Tamper evidence

Each row of the log is signed with the institution's audit key (Ed25519) and chained to the row before it, by a hash of that row. Altering, removing or inserting a row after the fact breaks the chain, and the break shows where it happened. Every release checks the chain after deploying, and `php artisan workspace:audit:verify` checks it at any time.

The audit key is kept outside the code, readable only by the web server; a restored server must keep the same key for the chain to verify.

## What it is for

The log answers the questions an archive is asked: who changed this description, when was this file added, who published this, who saw the audit log itself. It is the evidence behind the Workspace's claim that its records are authentic.
