#!/bin/sh
# OpenRiC Workspace - check an offline catalogue against its checksums (roadmap phase 11c), after Heratio's verify.sh.
# Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
# SPDX-License-Identifier: AGPL-3.0-or-later
# No network, no Workspace needed. Run it in the package folder:  sh verify.sh
cd "$(dirname "$0")" || exit 1
[ -f SHA256SUMS ] || { echo "SHA256SUMS is not here."; exit 1; }
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum -c SHA256SUMS
elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 -c SHA256SUMS
else
    echo "This needs sha256sum (Linux) or shasum (macOS)."; exit 1
fi
