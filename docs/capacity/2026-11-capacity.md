# Capacity test, November 2026

The first capacity test of the OpenRiC Workspace (roadmap R1 hardening, asked for by the external production-readiness assessment of 7 November 2026). It was run on 9 November 2026 against v0.0.145 before release, by `bin/capacity`, in a database of its own (`openric_workspace_capacity`), which is kept for the next run.

## What was loaded

`bin/capacity seed` made the data the way the application makes it, through the same services, audited and signed, so that what is measured is what an institution's own records would be:

| What | How many |
| --- | --- |
| Units of description | 100,000, in 93 top-level units: one fonds of 10,000 items directly under it, one branch eight levels deep, the rest fonds of series, files and items |
| Authority records | 10,000 |
| Places | 2,000 |
| Dated activities | 500 |
| Relations | 26,149 (creators, subjects, places) |
| Names and description values | 113,802 and 36,872 |
| Scans in the object store | 300 |
| Audit rows, chained and signed | 563,088 |
| Search index entries, public and staff | 219,002 |
| Database size | 1,237 MB |

: The capacity dataset

Loading took 162 minutes on the development host (24 cores, 251 GB of memory, PostgreSQL 16.15), shared with other work at a load average of 20 to 37: 302 seconds for the authority records and places, then the units at 5 to 20 a second as the caches warmed, the relations and activities, publishing (45 minutes) and the scans (28 minutes). That is the speed of description through the services, each change audited and signed one by one; a bulk import is measured below.

## The public catalogue

The first measurement was of the demonstration Workspace itself, at 980 records: its catalogue home made 10,702 queries and took 13 to 17 seconds, a fonds page 5.5 seconds. Each record's check of what the public may read asked the database again for the Public role's permissions (5,446 queries), walked up its parents one query at a time (3,118), and asked for its embargoes (990) and redactions (980). v0.0.145 asks for the permissions once per request, walks the parents only when some permission is held on a single record, and asks for the embargoes and redactions of a whole list in one query each. The home page now makes 106 queries and takes 0.3 seconds; a test keeps the number of queries the same for 25 records as for 5.

At 100,000 units that is not enough. Every public list loads all published records and decides each in PHP; above 65,535 records the eager loading of their names exceeds PostgreSQL's limit on a query's parameters, and the page fails after about two minutes. That is the catalogue home and every public record page, which loads the whole list for its strip of thumbnails, and with them the browse pages, OAI-PMH lists, IIIF change discovery, the site's blocks and DOI batches (18 places in all). Pages about one entity scale:

| Page | Time | Queries |
| --- | --- | --- |
| An authority record, public | 88 ms | 67 |
| A record through the API | 210 ms | 151 |
| A record's public page | fails after 120 s | |
| The catalogue home | fails after 105 s | |

: Single pages at 100,000 units, measured in the process before any load

So the load test of pages (`bin/capacity pages`: k6 2.3.0, readers and archivists at 1, 10 and 25 at once) could not run, and waits for **v0.0.146**, which decides public visibility in SQL, with a test that SQL and the check in PHP agree on what the public may read, and lists that count and page in the database. The test is then run again and this report extended.

## Search (phase 25a)

| Search | Found | Time |
| --- | --- | --- |
| The words `minutes` | 10,446 | 239 ms |
| `minuets`, shown as close matches | 10,446 | 724 ms |
| The regular expression `^minutes of` | 49 | 435 ms |
| `19[0-4][0-9]` | 42,016 | 560 ms |
| `water.{0,20}supply` | 7,009 | 802 ms |

: Public searches over 219,002 index entries

Every regular expression stayed well inside its five-second limit.

## Exports, import and checks

| Job | Time | Peak memory |
| --- | --- | --- |
| EAD 2002 of the fonds of 10,000 items (3.7 MB) | 43 s | |
| EAD3 of the same (3.8 MB) | 41 s | |
| RiC-O JSON-LD of the same (6.8 MB) | 44 s | |
| RiC-O Turtle of the same (2.9 MB) | 167 s | |
| Import of a 1,000-row spreadsheet: dry run, then commit | 48 s and 47 s | 30 MB |
| Rebuilding the search index (113,502 entities) | 660 s | 106 MB |
| Verifying the audit chain (563,088 rows) | 232 s | 83 MB |
| Verifying the object store (300 objects) | 0.7 s | 81 MB |

: Background work at 100,000 units

Every one finished and none ran short of memory. Two stand out: the Turtle export takes four times as long as the same graph as JSON-LD, which points at its serialiser rather than at the data, and is looked at with v0.0.146; and an import commits about 21 rows a second, so an institution's first migration of 100,000 rows is an overnight job, run as one. The import's one issue was its standing note that imported values are drafts until someone publishes them.

## Not measured

- The Fuseki rebuild for SPARQL, which is off in the capacity Workspace.
- Pages under load, until v0.0.146.
- Afrikaans, French and other interface languages, which do not change the work a page does.

## How to run it again

`bin/capacity setup` (once), `bin/capacity seed <password>` (once; it is kept), then `bin/capacity pages <password>` and `bin/capacity jobs <password>`. Results land in `storage/capacity/results/<date>/`. `bin/capacity reset empty-it` empties the database, and refuses any database whose name does not end in `_capacity`.
