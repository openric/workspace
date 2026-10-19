# OpenRiC Workspace - G1 Heratio source audit.
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
# Usage: python3 g1_heratio_audit.py <heratio checkout> <output dir>
"""G1 Heratio source audit, first pass: an inventory of every package at a pinned commit.

Read-only. For each package: PHP files and lines, the licence its composer.json
declares, how many PHP files carry a licence header and which licences they
name, the other Heratio packages it requires, and how tightly it is tied to the
AtoM/Qubit schema (references to its core tables). The scope column is a
proposed classification against the Workspace's archival scope, for review.
"""
import csv, json, pathlib, re, subprocess, sys
from collections import Counter

ROOT = pathlib.Path(sys.argv[1]); OUT = pathlib.Path(sys.argv[2]); OUT.mkdir(parents=True, exist_ok=True)
commit = subprocess.run(["git", "-C", str(ROOT), "rev-parse", "HEAD"], capture_output=True, text=True).stdout.strip()

ATOM_TABLES = re.compile(r"\b(information_object(_i18n)?|actor_i18n|term_i18n|slug|qubit\w*|object_term_relation|status|digital_object|event_i18n|property_i18n|setting_i18n)\b", re.I)
LICENCE = re.compile(r"(AGPL-3\.0(-or-later)?|GNU Affero General Public License|GPL-[23]\.0|MIT License|Apache License|BSD|proprietary|Copyright)", re.I)

SCOPE = [  # first match wins; proposed, for review
    ("out: GLAM sector", r"museum|library|gallery|dam$|spectrum|exhibition|loan|archaeology|artwork|biblio|harris-matrix|marketplace|iiif-collection|image-ar|3d-model|z3950|mods"),
    ("out: AI (V2+)", r"^ahg-ai-|semantic-search|provenance-ai|inference-receipts|translation"),
    ("later: jurisdiction", r"narssa|naz$|nmmz|cdpa|ipsas|icip|privacy|security-clearance|popia"),
    ("later: V1.0+", r"accession|donor|ingest|archivematica|preservation|ocfl|integrity|records-manage|federation|resourcesync|doi|sharepoint|ftp-upload|scan|harvest|loan|condition|reports|statistics|jobs|workflow|version-control|multi-tenant|graphql|portable-export|data-migration|dedupe|rdm|marketing|landing-page|articles|feedback|favorites|cart|share-link|label|static-page|help|functions-docs|observability|backup|vendor"),
    ("V0.1 candidate", r"core|api$|acl|user-manage|settings|theme|display|menu|dropdown|term-taxonomy|information-object|actor-manage|repository-manage|function-manage|rights|extended-rights|audit-trail|search|discovery|export|metadata-export|oai|ric|media-processing|media-streaming|storage|request-publish|access-request|custom-fields|forms|pdf-tools|metadata-extraction|authority-resolution|dacs|rad|dc-manage|research|researcher|gis|annotations|heritage|c2pa|api-plugin|rights-holder"),
]
def scope_of(name):
    for label, pat in SCOPE:
        if re.search(pat, name): return label
    return "unclassified"

rows = []
for pkg in sorted(p for p in (ROOT / "packages").iterdir() if p.is_dir()):
    php = [f for f in pkg.rglob("*.php") if "vendor" not in f.parts and "node_modules" not in f.parts]
    lines = 0; headers = Counter(); atom_files = 0
    for f in php:
        try: t = f.read_text(errors="ignore")
        except Exception: continue
        lines += t.count("\n")
        head = t[:1500]
        m = LICENCE.findall(head)
        headers[("AGPL" if any("Affero" in x[0] or x[0].upper().startswith("AGPL") for x in m) else (m[0][0] if m else "none"))] += 1
        if ATOM_TABLES.search(t): atom_files += 1
    comp = {}
    cj = pkg / "composer.json"
    if cj.exists():
        try: comp = json.loads(cj.read_text())
        except Exception: comp = {}
    lic = comp.get("license", "none")
    if isinstance(lic, list): lic = "/".join(lic)
    reqs = sorted(k for k in (comp.get("require") or {}) if k.startswith("ahg/"))
    ext = sorted(k for k in (comp.get("require") or {}) if not k.startswith(("ahg/", "php", "ext-", "illuminate/", "laravel/")))
    rows.append({"package": pkg.name, "php_files": len(php), "lines": lines, "composer_licence": lic,
                 "files_agpl_header": headers.get("AGPL", 0), "files_no_header": headers.get("none", 0),
                 "other_headers": "; ".join(f"{k}:{v}" for k, v in headers.items() if k not in ("AGPL", "none")),
                 "atom_schema_files": atom_files, "requires_ahg": " ".join(reqs), "external_deps": " ".join(ext),
                 "proposed_scope": scope_of(pkg.name)})

with open(OUT / "heratio-packages.csv", "w", newline="") as fh:
    w = csv.DictWriter(fh, fieldnames=list(rows[0])); w.writeheader(); w.writerows(rows)
tot = lambda k: sum(r[k] for r in rows)
by_scope = Counter(r["proposed_scope"] for r in rows)
lic = Counter(r["composer_licence"] for r in rows)
summary = {"commit": commit, "packages": len(rows), "php_files": tot("php_files"), "lines": tot("lines"),
           "files_agpl_header": tot("files_agpl_header"), "files_no_header": tot("files_no_header"),
           "atom_schema_files": tot("atom_schema_files"), "composer_licences": dict(lic), "by_proposed_scope": dict(by_scope),
           "v01_candidates": [r["package"] for r in rows if r["proposed_scope"] == "V0.1 candidate"],
           "unclassified": [r["package"] for r in rows if r["proposed_scope"] == "unclassified"]}
(OUT / "heratio-audit-summary.json").write_text(json.dumps(summary, indent=2))
print(json.dumps(summary, indent=2))
