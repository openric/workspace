#!/bin/bash
# OpenRiC Workspace - the container's entrypoint: the first-boot install (idempotent, docker/init.sh), then the CMD
# (supervisord). Split, after Heratio's, so a failing install stops the container with a clear log instead of
# serving errors half-installed.
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
openric-workspace-init
exec "$@"
