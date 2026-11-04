---
title: The object store
category: Preservation
order: 60
staff: true
heratio: ocfl-storage.md
---

# The object store

Every file the Workspace keeps, every master, copy, caption track, package and site file, is kept in one object store laid out by the Oxford Common File Layout (OCFL) 1.1. OCFL is a plain, documented arrangement of folders and files with an inventory of checksums, so the store can be read, checked and moved without the Workspace.

## How it is laid out

- Each instantiation is one OCFL object, named by its persistent IRI, holding its master, copies and caption tracks under their own names.
- Each change, such as a new copy, makes a new version of the object holding the whole object as it then is; earlier versions stay.
- Each object's inventory lists every file with its SHA-256, version by version.
- Objects are placed in the store by OCFL's extension 0004 (hashed n-tuple storage), so no folder grows too large.

Packages, Europeana bundles and the public site's files each have objects of their own too.

## Checking it

`php artisan workspace:ocfl:verify` checks every object against its inventory and checksums. Each release runs it after deploying; see also [Fixity](/help/article/dp-fixity-and-checksums).

## Deleting

A file is never edited in place. A destruction, approved under the records management rules, purges the object, every version of it; anything less leaves the object in the store.

## Replication

**Replication** (under **Preservation**) copies the store to other places: a local folder, such as another disk, or another server by rsync over SSH. Each target has its schedule, and each copy is recorded with what was sent. **Compare the copy** checks a target against the store, file by file, so a damaged file can be found and a good copy taken from the other side.

A target on another server needs an SSH key the web server's user can read, and the server's host key accepted once; see the installation guide.
