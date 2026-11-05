<?php

// OpenRiC Workspace - AI could not be asked: not set up, switched off, over its limit, or the gateway did not answer
// (roadmap phase 17a). Its message says which, for the person who asked.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

use RuntimeException;

class AiUnavailable extends RuntimeException {}
