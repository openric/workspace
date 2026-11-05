<?php

/**
 * EvidenceSignal - Service for Heratio. The four signals an evaluator gives, and the weight each adds to a
 * candidate's name similarity: composite = clamp(name_similarity + sum(weights), 0, 1).
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/EvidenceSignal.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed:
 * the namespace and name. These are the scoring algorithm's own values, kept in code as Heratio keeps them, not lists
 * an institution chooses from.
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

final class Signal
{
    public const MATCH = 'match';

    public const CONFLICT = 'conflict';

    public const SILENT = 'silent';

    public const ABSENT = 'absent';

    public const VALID = [self::MATCH, self::CONFLICT, self::SILENT, self::ABSENT];

    public const WEIGHT = [self::MATCH => 0.10, self::CONFLICT => -0.30, self::SILENT => 0.0, self::ABSENT => 0.0];

    /** @return array{signal:string,data:array<string,mixed>} */
    public static function make(string $signal, array $data = []): array
    {
        return ['signal' => in_array($signal, self::VALID, true) ? $signal : self::ABSENT, 'data' => $data];
    }
}
