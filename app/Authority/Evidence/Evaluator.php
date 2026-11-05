<?php

/**
 * EvaluatorInterface - Service for Heratio. One dimension of evidence for a (mention, candidate) pair.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/EvaluatorInterface.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed:
 * the mention and candidate are models, and the candidate's facts come from Facts.
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

interface Evaluator
{
    /** The dimension's key in a candidate's signals: temporal, geographic, relational, role, conflict, co_occurring, hierarchical, scale, document_prior. */
    public function dimension(): string;

    /** spaCy's label of the mention: PERSON, ORG, GPE, LOC, PLACE. */
    public function supports(string $label): bool;

    /** @return array{signal:string,data:array<string,mixed>} */
    public function evaluate(AuthorityMention $mention, AuthorityCandidate $candidate, Facts $facts): array;
}
