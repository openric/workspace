# OpenRiC Workspace - G1 database spike (ADR 0001).
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Run the same synthetic, RiC-shaped workload on PostgreSQL or MySQL and
report timings and capabilities as JSON.

Synthetic data only: no Heratio data, credentials or code. The schema is a
spike, not the Workspace's data model.

  python3 spike.py mysql "host=localhost db=openric_staging" [--fonds 100]
  python3 spike.py postgres "dbname=openric_spike" [--fonds 100]

Every run drops and recreates its own tables in the database it is given;
point it only at a disposable database.
"""
import argparse, json, random, statistics, time

p = argparse.ArgumentParser()
p.add_argument("engine", choices=["postgres", "mysql"])
p.add_argument("dsn")
p.add_argument("--fonds", type=int, default=100, help="fonds of 1,111 records each (fonds/10 series/100 files/1000 items)")
p.add_argument("--agents", type=int, default=20000)
p.add_argument("--repeat", type=int, default=5)
a = p.parse_args()
random.seed(20261019)

if a.engine == "postgres":
    import psycopg2 as drv
    conn = drv.connect(a.dsn); PK = "BIGINT PRIMARY KEY"
else:
    import pymysql as drv
    kv = dict(x.split("=", 1) for x in a.dsn.split())
    conn = drv.connect(host=kv.get("host", "localhost"), database=kv["db"], user=kv.get("user"),
                       password=kv.get("password", ""), unix_socket=kv.get("socket"), charset="utf8mb4", autocommit=False)
    PK = "BIGINT PRIMARY KEY"
cur = conn.cursor()
PG = a.engine == "postgres"
TEXT = "TEXT"

def run(sql, args=None):
    cur.execute(sql, args or ())

def timed(fn, n=a.repeat):
    ts = []
    for _ in range(n):
        t = time.perf_counter(); out = fn(); ts.append(time.perf_counter() - t)
    return {"median_ms": round(statistics.median(ts) * 1000, 2), "min_ms": round(min(ts) * 1000, 2), "result": out}

# Schema ---
# Foreign keys are table-level: MySQL parses an inline column REFERENCES clause and silently ignores it.---------------------------------------------------------------
for t in ("relation", "user_grant", "entity_i18n", "entity"):
    run(f"DROP TABLE IF EXISTS {t}" + (" CASCADE" if PG else ""))
run(f"""CREATE TABLE entity (
  id {PK}, kind VARCHAR(16) NOT NULL, identifier VARCHAR(64) NOT NULL UNIQUE,
  source_culture CHAR(2) NOT NULL, parent_id BIGINT NULL, root_id BIGINT NULL,
  access VARCHAR(12) NOT NULL DEFAULT 'open', embargo_until DATE NULL,
  CONSTRAINT access_ok CHECK (access IN ('open','restricted','embargoed')),
  CONSTRAINT not_own_parent CHECK (parent_id IS NULL OR parent_id <> id),
  FOREIGN KEY (parent_id) REFERENCES entity(id))""")
run("CREATE INDEX entity_parent ON entity(parent_id)")
run("CREATE INDEX entity_root ON entity(root_id, kind)")
run(f"""CREATE TABLE entity_i18n (
  entity_id BIGINT NOT NULL, culture CHAR(2) NOT NULL,
  title VARCHAR(512) NOT NULL, scope_and_content {TEXT}, PRIMARY KEY (entity_id, culture), FOREIGN KEY (entity_id) REFERENCES entity(id))""")
run(f"""CREATE TABLE relation (
  id {PK}, subject_id BIGINT NOT NULL, object_id BIGINT NOT NULL,
  predicate VARCHAR(64) NOT NULL, asserted_by VARCHAR(64) NOT NULL, evidence VARCHAR(255) NULL,
  certainty VARCHAR(12) NOT NULL DEFAULT 'asserted',
  CONSTRAINT certainty_ok CHECK (certainty IN ('asserted','inferred','disputed')),
  CONSTRAINT one_assertion UNIQUE (subject_id, object_id, predicate, asserted_by),
  FOREIGN KEY (subject_id) REFERENCES entity(id), FOREIGN KEY (object_id) REFERENCES entity(id))""")
run("CREATE INDEX relation_object ON relation(object_id, predicate)")
run("CREATE TABLE user_grant (user_id BIGINT NOT NULL, root_id BIGINT NOT NULL, PRIMARY KEY (user_id, root_id))")

# Cycle prevention in the database: a record may not be moved under its own descendant.
if PG:
    run("""CREATE OR REPLACE FUNCTION no_cycle() RETURNS trigger AS $$
    BEGIN
      IF NEW.parent_id IS NOT NULL AND EXISTS (
        WITH RECURSIVE up(id, parent_id) AS (
          SELECT id, parent_id FROM entity WHERE id = NEW.parent_id
          UNION ALL SELECT e.id, e.parent_id FROM entity e JOIN up ON e.id = up.parent_id)
        SELECT 1 FROM up WHERE id = NEW.id) THEN
        RAISE EXCEPTION 'arrangement cycle';
      END IF; RETURN NEW;
    END $$ LANGUAGE plpgsql""")
    run("CREATE TRIGGER entity_no_cycle BEFORE UPDATE OF parent_id ON entity FOR EACH ROW EXECUTE FUNCTION no_cycle()")
    trigger_note = True
else:
  run("DROP TRIGGER IF EXISTS entity_no_cycle")
  try:
    run("""CREATE TRIGGER entity_no_cycle BEFORE UPDATE ON entity FOR EACH ROW
    BEGIN
      IF NEW.parent_id IS NOT NULL AND EXISTS (
        WITH RECURSIVE up(id, parent_id) AS (
          SELECT id, parent_id FROM entity WHERE id = NEW.parent_id
          UNION ALL SELECT e.id, e.parent_id FROM entity e JOIN up ON e.id = up.parent_id)
        SELECT 1 FROM up WHERE id = NEW.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'arrangement cycle';
      END IF;
    END""")
    trigger_note = True
  except drv.err.OperationalError as e:
    # With binary logging on, creating a trigger needs SUPER or log_bin_trust_function_creators.
    conn.rollback(); trigger_note = f"refused for the application user: {e.args[1][:90]}"
conn.commit()

# Synthetic data ------------------------------------------------------------
cultures = ["en"] * 6 + ["af"] * 3 + ["fr"]
ents, i18n, rels, nid = [], [], [], 0
def add(kind, parent, root):
    global nid
    nid += 1; sc = random.choice(cultures)
    acc = random.choices(["open", "restricted", "embargoed"], [85, 10, 5])[0]
    emb = "2027-01-01" if acc == "embargoed" and random.random() < .5 else ("2020-01-01" if acc == "embargoed" else None)
    ents.append((nid, kind, f"{kind}-{nid}", sc, parent, root or nid, acc, emb))
    i18n.append((nid, sc, f"{kind} {nid} ({sc})", f"Scope and content of {kind} {nid}."))
    for c in ("en", "af", "fr"):
        if c != sc and random.random() < (.3 if c == "af" else .1):
            i18n.append((nid, c, f"{kind} {nid} ({c})", None))
    return nid
for _ in range(a.fonds):
    f = add("fonds", None, None)
    for _ in range(10):
        s = add("series", f, f)
        for _ in range(10):
            fi = add("file", s, f)
            for _ in range(10): add("item", fi, f)
deep = add("fonds", None, None); chain = deep
for _ in range(30): chain = add("file", chain, deep)
items = [e[0] for e in ents if e[1] == "item"]
agents = [add("agent", None, None) for _ in range(a.agents)]
for i, it in enumerate(items):
    rels.append((i + 1, it, random.choice(agents), "rico:hasCreator", "archivist-1", "accession register", "asserted"))
    if random.random() < .05:
        rels.append((len(items) + i + 1, it, random.choice(agents), "rico:hasCreator", "ai-suggest", "model output", "inferred"))
fonds_ids = [e[0] for e in ents if e[1] == "fonds" and e[0] != deep]

def load():
    # tables are freshly created; parents come before children in creation order
    for sql, rows in (("INSERT INTO entity VALUES (%s,%s,%s,%s,%s,%s,%s,%s)", ents),
                      ("INSERT INTO entity_i18n VALUES (%s,%s,%s,%s)", i18n),
                      ("INSERT INTO relation VALUES (%s,%s,%s,%s,%s,%s,%s)", rels)):
        for k in range(0, len(rows), 5000): cur.executemany(sql, rows[k:k + 5000])
    cur.executemany("INSERT INTO user_grant VALUES (%s,%s)", [(7, r) for r in fonds_ids[:5]])
    conn.commit()
    return {"entities": len(ents), "i18n_rows": len(i18n), "relations": len(rels)}

report = {"engine": a.engine, "fonds": a.fonds, "repeat": a.repeat}
cur.execute("SELECT version()"); report["server"] = cur.fetchone()[0]
report["load"] = timed(load, 1)
if PG: run("ANALYZE")
else:
    for t in ("entity", "entity_i18n", "relation", "user_grant"): run(f"ANALYZE TABLE {t}"); cur.fetchall()
conn.commit()

DESC = """WITH RECURSIVE d(id) AS (SELECT id FROM entity WHERE id = %s
          UNION ALL SELECT e.id FROM entity e JOIN d ON e.parent_id = d.id) SELECT count(*) FROM d"""
ANC = """WITH RECURSIVE u(id, parent_id, depth) AS (SELECT id, parent_id, 0 FROM entity WHERE id = %s
         UNION ALL SELECT e.id, e.parent_id, u.depth + 1 FROM entity e JOIN u ON e.id = u.parent_id)
         SELECT max(depth) FROM u"""
def q(sql, args):
    cur.execute(sql, args); return cur.fetchone()[0]
report["descendants_of_fonds"] = timed(lambda: q(DESC, (fonds_ids[len(fonds_ids) // 2],)))
report["ancestors_depth_31"] = timed(lambda: q(ANC, (chain,)))

# Multilingual: a page of children in the requested culture, falling back to the source culture.
ML = """SELECT e.id, COALESCE(r.title, s.title) FROM entity e
        LEFT JOIN entity_i18n r ON r.entity_id = e.id AND r.culture = %s
        JOIN entity_i18n s ON s.entity_id = e.id AND s.culture = e.source_culture
        WHERE e.root_id = %s AND e.kind = 'item' ORDER BY e.id LIMIT 50"""
def ml():
    cur.execute(ML, ("af", fonds_ids[3])); rows = cur.fetchall()
    return {"rows": len(rows), "distinct_ids": len({r[0] for r in rows})}
report["multilingual_page"] = timed(ml)

# Access: what user 7 may see in a fonds (open, embargo past, or restricted with a grant on the fonds).
ACC = """SELECT count(*) FROM entity e WHERE e.root_id = %s AND (
           e.access = 'open'
        OR (e.access = 'embargoed' AND e.embargo_until IS NOT NULL AND e.embargo_until < CURRENT_DATE)
        OR (e.access = 'restricted' AND EXISTS (SELECT 1 FROM user_grant g WHERE g.user_id = %s AND g.root_id = e.root_id)))"""
report["access_filter_granted"] = timed(lambda: q(ACC, (fonds_ids[0], 7)))
report["access_filter_not_granted"] = timed(lambda: q(ACC, (fonds_ids[50 % len(fonds_ids)], 7)))

# Evidence: competing creator assertions kept side by side.
EV = """SELECT r.certainty, count(*) FROM relation r WHERE r.predicate = 'rico:hasCreator' GROUP BY r.certainty ORDER BY 1"""
def ev():
    cur.execute(EV); return dict((k, int(v)) for k, v in cur.fetchall())
report["assertions_by_certainty"] = timed(ev)

# Constraints the database itself enforces.
def refused(sql, args=()):
    try:
        cur.execute(sql, args); conn.commit(); return False
    except Exception as e:
        conn.rollback(); return type(e).__name__ + ": " + str(e).splitlines()[0][:80]
f0 = fonds_ids[0]
cur.execute("SELECT id FROM entity WHERE root_id = %s AND kind = 'item' LIMIT 1", (f0,)); leaf = cur.fetchone()[0]
report["constraints"] = {
    "cycle_refused": refused("UPDATE entity SET parent_id = %s WHERE id = %s", (leaf, f0)),
    "self_parent_refused": refused("UPDATE entity SET parent_id = id WHERE id = %s", (f0,)),
    "dangling_parent_refused": refused("UPDATE entity SET parent_id = 999999999 WHERE id = %s", (leaf,)),
    "bad_access_value_refused": refused("UPDATE entity SET access = 'secret' WHERE id = %s", (leaf,)),
    "duplicate_assertion_refused": refused("INSERT INTO relation VALUES (%s,%s,%s,%s,%s,%s,%s)", (10**9,) + rels[0][1:]),
}
report["capabilities"] = {
    "cycle_trigger_created": trigger_note,
    "row_level_security": "available, not exercised (needs a role to test)" if PG else "none: emulate with views or the application",
    "transactional_ddl": PG,
    "deferrable_constraints": PG,
}
print(json.dumps(report, indent=2, default=str))
