<?php

/**
 * RoleEvaluator - Service for Heratio. A person or organisation candidate whose history names a word of role found near the mention is a match.
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio, released under the GNU Affero General Public License, version 3 or later.
 *
 * OpenRiC Workspace - copied from Heratio, packages/ahg-authority-resolution/src/Services/Evidence/RoleEvaluator.php
 * at commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17b2). Changed: the history is the candidate's ISAAR(CPF) history, functions, mandates and legal status (Facts).
 */

declare(strict_types=1);

namespace App\Authority\Evidence;

use App\Models\AuthorityCandidate;
use App\Models\AuthorityMention;

class RoleEvaluator implements Evaluator
{
    public function dimension(): string
    {
        return 'role';
    }

    public function supports(string $label): bool
    {
        return in_array($label, AuthorityMention::PERSON_ORG, true);
    }

    public function evaluate(AuthorityMention $mention, AuthorityCandidate $candidate, Facts $facts): array
    {
        if ($candidate->candidate_table !== 'agents') {
            return Signal::make(Signal::ABSENT, ['reason' => 'candidate_not_an_agent']);
        }
        $tokens = array_values(array_unique(array_filter(array_map(fn ($r) => (string) ($r['token'] ?? ''), $mention->role_tokens ?? []))));
        $history = $facts->history($candidate->candidate_id);
        if ($tokens === [] || $history === null) {
            return Signal::make(Signal::ABSENT, ['reason' => $tokens === [] ? ($history === null ? 'no_role_tokens_and_no_candidate_history' : 'no_role_tokens_in_mention') : 'no_candidate_history_text',
                'role_tokens' => $tokens]);
        }
        $hits = array_values(array_filter($tokens, fn ($t) => str_contains(mb_strtolower($history), mb_strtolower($t))));

        return $hits !== []
            ? Signal::make(Signal::MATCH, ['matched_tokens' => $hits, 'all_role_tokens' => $tokens])
            : Signal::make(Signal::SILENT, ['reason' => 'no_token_appears_in_history', 'role_tokens' => $tokens]);
    }
}
