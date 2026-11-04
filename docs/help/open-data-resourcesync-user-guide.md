---
title: ResourceSync
category: Federation and open data
order: 20
heratio: open-data-resourcesync-user-guide.md
---

# ResourceSync

ResourceSync (ANSI/NISO Z39.99-2017) is a way for a harvester to keep its copy of a catalogue in step: it reads the list of everything once, then only what changed since. The Workspace offers it at the same addresses as Heratio.

## The documents

| Address | What it is |
|---|---|
| `/.well-known/resourcesync` | The source description: where to start |
| `/resourcesync/capabilitylist.xml` | What is offered: the resource list and the change list |
| `/resourcesync/resourcelist.xml` | Every published entity, by its persistent IRI, with when it last changed |
| `/resourcesync/changelist.xml?from=` | What was made, changed or withdrawn since a moment |

Each is a sitemap with ResourceSync's own additions, so ordinary sitemap tools can read it too.

## The change list

`from` takes an ISO 8601 date and time, such as `2026-11-01T00:00:00Z`; without one, the list reaches 30 days back. Each entity is marked:

- **created**, if it was made since then;
- **updated**, if it was changed;
- **deleted**, if it was withdrawn, which ResourceSync calls deleted even though its IRI still answers that it has been withdrawn.

## Paging

Both lists come 1,000 entries to a page, with `?page=2` and so on, each page linking the next and the one before. An installation can change the page size and the change list's horizon (`RESOURCESYNC_PAGE_SIZE`, `RESOURCESYNC_CHANGELIST_DAYS`).

## Only what is published

As with every open interface, ResourceSync lists only what the public may read. A draft never appears, and an entity that is withdrawn appears once, as deleted.
