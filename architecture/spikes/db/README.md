# G1 database spike

Evidence for ADR 0001: PostgreSQL against MySQL for the Workspace's canonical store. `spike.py` runs the same synthetic, RiC-shaped workload on either engine and prints a JSON report. No Heratio data, code or credentials are used.

## Workload

100 fonds of 1,111 records each (fonds, 10 series, 100 files, 1,000 items), one 31-level deep fonds, and 20,000 agents: 131,131 entities, about 176,000 title rows in English, Afrikaans and French, and about 105,000 creator assertions, 5% of them a competing machine suggestion marked `inferred`.

| Test | What it shows |
|---|---|
| load | bulk insert in batches of 5,000 |
| descendants_of_fonds | recursive arrangement downwards (1,111 records) |
| ancestors_depth_31 | recursive path upwards from the deepest record |
| multilingual_page | 50 children in a requested language, falling back to the source language, one row each |
| access_filter_granted / _not_granted | open records, lapsed embargoes and restricted records with a grant |
| assertions_by_certainty | competing assertions kept side by side |
| constraints | what the database refuses on its own: cycles, self-parent, dangling parent, bad access value, duplicate assertion |

: Spike tests

Timings are the median of five runs on the main host, which is also serving production, so they show orders of magnitude, not benchmarks.

## Results so far

MySQL 8.0.46 (`results/mysql-8.0.46.json`, 19 October 2026), in the disposable `openric_staging` database:

- Load: 16.4 s. Descendants of a fonds: 3.5 ms. Ancestors over 31 levels: 0.5 ms. Multilingual page: 1.4 ms, no duplicated rows. Access filter: about 3 ms. Assertions grouped by certainty: 158 ms over the whole relation table.
- Refused by the database: self-parent, dangling parent, bad access value, duplicate assertion.
- **Not refused: an arrangement cycle.** The cycle check needs a trigger, and with binary logging on, MySQL refuses `CREATE TRIGGER` to an application user without SUPER or `log_bin_trust_function_creators`. On a typical managed or hardened MySQL the rule would live in application code.
- **A trap found on the way:** MySQL accepts a foreign key written inline on a column (`parent_id BIGINT REFERENCES entity(id)`) and silently ignores it. The first run accepted a dangling parent for that reason. The harness now writes table-level `FOREIGN KEY` clauses.
- No row-level security, no transactional DDL, no deferrable constraints.

PostgreSQL 16.15 (`results/postgresql-16.15.json`, 19 October 2026), in the disposable `openric_spike` database:

- Load: 21.8 s. Descendants of a fonds: 3.1 ms. Ancestors over 31 levels: 0.3 ms. Multilingual page: 2.1 ms, no duplicated rows. Access filter: about 0.8 ms. Assertions grouped by certainty: 58 ms.
- Refused by the database: all five, **including the arrangement cycle**, through a trigger the application user could create without extra privileges.
- Row-level security, transactional DDL and deferrable constraints are all available.
- The first PostgreSQL load took 56.7 s because psycopg2's `executemany` sends one statement per row while pymysql batches. The harness now batches on both (`execute_values` for PostgreSQL), so the load figures compare engines, not drivers.

| | MySQL 8.0.46 | PostgreSQL 16.15 |
|---|---|---|
| Load, 131,131 entities | 16.4 s | 21.8 s |
| Descendants of a fonds | 3.5 ms | 3.1 ms |
| Ancestors, 31 levels | 0.4 ms | 0.3 ms |
| Multilingual page | 1.4 ms | 2.1 ms |
| Access filter | 2.8 ms | 0.8 ms |
| Assertions by certainty | 159 ms | 58 ms |
| Arrangement cycle refused by the database | no | yes |
| Row-level security | no | yes (not yet exercised) |
| Transactional DDL (a failed migration rolls back) | no | yes |

: MySQL and PostgreSQL on the spike workload

Speed does not separate them at this size. Integrity does: PostgreSQL enforces every rule the workload needs inside the database, and MySQL leaves the arrangement rule to application code.

To re-run either:

    python3 -I spike.py postgres "dbname=openric_spike" > results/postgresql-16.15.json
    python3 -I spike.py mysql "db=openric_staging socket=/var/run/mysqld/mysqld.sock" > results/mysql-8.0.46.json

## Not covered yet

Row-level security in practice (needs a second role), concurrent writers, full-text search (a projection in ADR 0001), and migration adapters from Heratio, which wait on the G1 audit.
