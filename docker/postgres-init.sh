#!/bin/bash
# OpenRiC Workspace - run once by the PostgreSQL container when its volume is new: the Workspace's own role, its
# database, and pgvector, which only the superuser may make (pg_trgm and unaccent the migrations make themselves).
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
psql -v ON_ERROR_STOP=1 --username postgres -v role="$WORKSPACE_DB_USER" -v pass="$WORKSPACE_DB_PASSWORD" -v db="$WORKSPACE_DB" <<'SQL'
CREATE ROLE :"role" LOGIN PASSWORD :'pass';
CREATE DATABASE :"db" OWNER :"role" ENCODING 'UTF8' TEMPLATE template0;
\connect :"db"
CREATE EXTENSION IF NOT EXISTS vector;
SQL
