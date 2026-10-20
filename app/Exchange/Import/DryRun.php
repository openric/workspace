<?php

// OpenRiC Workspace - thrown at the end of a dry run of an import, to roll it back.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Exchange\Import;

use Exception;

final class DryRun extends Exception {}
