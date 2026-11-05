<?php

/**
 * GndAdapter - Service for Heratio. A stub in Heratio: listed in the settings, never searched, until its endpoint is written.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Lookup/Adapters/GndAdapter.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the namespace and name; like Heratio's, it answers nothing, and Settings::SEARCHED keeps it from being switched on.
 */

declare(strict_types=1);

namespace App\Authority\Lookup;

class Gnd extends Lookup
{
    public function source(): string
    {
        return 'gnd';
    }

    public function supports(string $label): bool
    {
        return in_array($label, ['PERSON', 'ORG'], true);
    }

    protected function fetchFromSource(string $query, string $label, int $limit): array
    {
        return [];
    }
}
