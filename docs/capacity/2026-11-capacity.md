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

The first measurement was of the demonstration Workspace itself, at 980 records: its catalogue home made 10,702 queries and took 13 to 17 seconds, a fonds page 5.5 seconds. Each record's check of what the public may read asked the database again for the Public role's permissions (5,446 queries), walked up its parents one query at a time (3,118), and asked for its embargoes (990) and redactions (980). v0.0.145 asks for the permissions once per request, walks the parents only when some permission is held on a single record, and asks for the embargoes and redactions of a whole list in one query each. The home page now makes about 190 queries, 0.65 seconds of them in the database, and takes about 5.5 seconds on the demonstration host at a load average near 40 (a fonds page 1.1 seconds, from 5.5); a test keeps the number of queries the same for 25 records as for 5. Most of what remains is the home page loading every public authority record (4,418, 3.0 seconds) and place (2,779, 2.1 seconds) only to count them, which v0.0.146 counts in SQL. (A figure of 0.3 seconds given with v0.0.145 was measured on the development database, which holds 39 records, and was wrong.)

At 100,000 units that is not enough. Every public list loads all published records and decides each in PHP; above 65,535 records the eager loading of their names exceeds PostgreSQL's limit on a query's parameters, and the page fails after about two minutes. That is the catalogue home and every public record page, which loads the whole list for its strip of thumbnails, and with them the browse pages, OAI-PMH lists, IIIF change discovery, the site's blocks and DOI batches (18 places in all). Pages about one entity scale:

| Page | Time | Queries |
| --- | --- | --- |
| An authority record, public | 88 ms | 67 |
| A record through the API | 210 ms | 151 |
| A record's public page | fails after 120 s | |
| The catalogue home | fails after 105 s | |

: Single pages at 100,000 units, measured in the process before any load

So the load test of pages could not run until **v0.0.146**, which decides what the public may read in SQL (`PublicCatalogue::ids()`), held by a test to the same answer as the check in PHP for every rule, and has every public list count, order and page in the database. On the development host, at 100,000 units, measured in the process:

| Page | Before v0.0.146 | v0.0.146 |
| --- | --- | --- |
| The catalogue home | fails after 105 s | 2.0 s |
| A record's public page | fails after 120 s | 1.6 to 1.8 s |
| The fonds of 10,000 items, its page | fails (157 s in one run) | 1.8 s |
| The API's list of records, page 200 of 50 | 8.6 s | 2.0 s |
| OAI-PMH Identify | 3.0 s | 1.4 s |
| OAI-PMH, a page of 100 identifiers | | 1.3 to 2.7 s |

: Public pages at 100,000 units, in the process

The largest single gain came from comparing names by their state's and type's ids rather than joining on the terms' IRIs: PostgreSQL then estimates from the column's statistics that 94,000 names are published, where it had guessed a few dozen and chosen plans that ran for seconds.

### Under load

`bin/capacity pages` (k6 2.3.0, pinned by checksum) ran readers and archivists, six to four, at 1, 10 and 25 at once for three minutes each, every one from an address of its own and reading for one to three seconds between pages, against 32 PHP workers, on a host shared with other work at a load average of 20 to 40. The time of each page, in milliseconds, half of them faster than the first figure and 95 in 100 faster than the second:

| Page | 1 user | 10 users | 25 users |
| --- | --- | --- | --- |
| Public authority record | 155 / 183 | 203 / 352 | 262 / 451 |
| Public tree of the widest fonds | 304 / 327 | 347 / 741 | 550 / 1,023 |
| Public search | 548 / 734 | 658 / 1,379 | 955 / 1,796 |
| Public record | 1,674 / 1,858 | 2,130 / 3,259 | 3,986 / 6,705 |
| Deepest public record | 2,096 / 2,168 | 2,579 / 5,469 | 4,587 / 7,325 |
| Catalogue home | 2,144 / 2,306 | 3,121 / 4,057 | 5,321 / 8,828 |
| OAI-PMH list | 2,920 / 2,982 | 4,339 / 7,718 | 7,709 / 11,319 |
| Dashboard | 137 / 161 | 152 / 268 | 198 / 308 |
| Record form | 226 / 241 | 250 / 379 | 351 / 543 |
| Saving a record | 216 / 241 | 218 / 343 | 267 / 496 |
| Staff search | 603 / 694 | 658 / 1,368 | 1,074 / 1,888 |
| Staff authority record | 290 / 327 | 300 / 423 | 433 / 755 |
| Staff tree of the widest fonds | 8,050 / 8,525 | 8,694 / 9,708 | 9,001 / 11,454 |
| Staff record | 18,001 / 18,232 | 20,602 / 31,536 | 24,262 / 29,023 |

: Pages under load at 100,000 units (median / 95th percentile, ms)

No request failed but three of 77 public record pages at 25 users, which answered 404 rightly: the probe had picked them for having a published name, though they were under embargo or closed; it now takes its records from the public catalogue's own query. The test's own thresholds (95 in 100 under one second up to ten users, under 2.5 seconds at 25) are not met:

- **The staff side** decides what an account may read one entity at a time, as the public side did before v0.0.146: a staff record's page takes 18 seconds, the staff tree of the widest fonds 8. Moving it into SQL as v0.0.146 did for the public is [openric/workspace#8](https://github.com/openric/workspace/issues/8).
- **The public pages that work out the visible set** (the home, a record's page, the OAI-PMH list) take two to three seconds alone and slow under load as they share the database. Each still asks for the set more than once a page; computing it once a request is the next step for them.
- Pages that read one entity, search, the tree, the forms and saving stay under two seconds throughout.

The first run of this test also found a fault in the test itself: k6 empties each user's cookies every iteration unless told not to, so the archivists, signed in once, were sent to the sign-in page from their second round on. `noCookiesReset` is now set.

## The staff side, 11 November (v0.0.153, openric/workspace#8)

The run of 9 November found the staff record page at 18 seconds and the staff tree of the widest fonds at 8: both loaded every unit inside the fonds, all 10,000, and checked each against the account's rights in PHP. v0.0.148 had already made the record page's lists of authority records one query. v0.0.153 reads the tree's branches, siblings and "has children" in order and stops as soon as a page is found (`RecordService::firstReadable()`); lists a record's units inside a hundred to a page, as the public page has since v0.0.146; and builds the hierarchy page from the units' titles, levels and reference codes read in three queries rather than their whole descriptions. The rights are still decided in PHP, a page's worth of them: where an account may read few of a branch's many units it reads the branch through, the ceiling marked in the code.

| Page | 1 user, 9 Nov | 1 user, 11 Nov | 10 users, 9 Nov | 10 users, 11 Nov | 25 users, 9 Nov | 25 users, 11 Nov |
| --- | --- | --- | --- | --- | --- | --- |
| Staff record | 18,232 | 1,299 | 31,536 | 1,439 | 29,023 | 963 |
| Hierarchy of the widest fonds | 8,525 | 4,811 | 9,708 | 4,262 | 11,454 | 4,134 |
| Staff search | 694 | 988 | 1,368 | 818 | 1,888 | 714 |
| Record form | 241 | 349 | 379 | 387 | 543 | 278 |
| Staff authority record | 327 | 642 | 423 | 619 | 755 | 430 |

: The staff pages at 100,000 units, 95th percentile in milliseconds, `bin/capacity pages`

No request failed at any load. The staff record now meets the target at 25 users and is 0.4 seconds over it at 10. The hierarchy page is the one still over: it lists all 10,000 units of the widest fonds on one page by design, and would need paging too to come under a second. Staff search can still show a short page where results the account may not read are dropped after paging, as its code notes.

### Later on 11 November (v0.0.154)

The hierarchy page now shows its fonds depth first, five hundred lines to a page: the order from one recursive query, the rights read only until the page is full, a unit the account may not read hiding what is inside it. Its 95th percentile at 100,000 units: 980 ms with one user, 905 with 10 and 803 with 25 (from 8,525, 9,708 and 11,454 on 9 November). No request failed.

The RiC-O exports of the fonds of 10,000 items (openric/workspace#7): its document loads the units inside it and beside it in batches, with their embargoes and redactions read together, where it read each at a time (137 queries where it made 77,824), and the Workspace's own JSON-LD is read into the RDF graph directly rather than by the general JSON-LD processor, held to the same triples by RdfTest. Turtle took 28 s (from 167) and JSON-LD 12 s (from 44). Of the 28 s, 16 are EasyRdf building and writing the graph. In the same run, EAD, the import and the search rebuild, whose code did not change, took 40 to 70 per cent longer than on 9 November, so the host was busier; they are not compared here.

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

Every one finished and none ran short of memory. Two stand out. The Turtle export takes four times as long as the same graph as JSON-LD; measured stage by stage, the Turtle writer takes 0.7 seconds, while turning the JSON-LD into RDF (`ml/json-ld`, through EasyRdf) takes 123 seconds for its 37,628 triples, which Turtle and RDF/XML pay and JSON-LD does not, and gathering the record and its 10,000 parts takes 43 seconds and 57,817 queries, which every format pays; a fonds somewhat larger would pass PHP's 300-second limit for a request ([openric/workspace#7](https://github.com/openric/workspace/issues/7)). And an import commits about 21 rows a second, so an institution's first migration of 100,000 rows is an overnight job, run as one. The import's one issue was its standing note that imported values are drafts until someone publishes them.

## Not measured

- The Fuseki rebuild for SPARQL, which is off in the capacity Workspace.
- Afrikaans, French and other interface languages, which do not change the work a page does.

## How to run it again

`bin/capacity setup` (once), `bin/capacity seed <password>` (once; it is kept), then `bin/capacity pages <password>` and `bin/capacity jobs <password>`. Results land in `storage/capacity/results/<date>/`. `bin/capacity reset empty-it` empties the database, and refuses any database whose name does not end in `_capacity`.
