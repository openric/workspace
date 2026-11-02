<?php

// OpenRiC Workspace - ODRL rights policies on records, for researchers, and the decisions taken under them (roadmap
// phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's OdrlService and OdrlPolicyMiddleware. A policy on a record permits, prohibits or obliges an action
// (use, reproduce, distribute, modify, archive, display), within its constraints: named accounts, a period, and a
// most number of uses. For an action on a record: a prohibition that holds denies; else a permission that holds
// permits; else, when the record has policies for the action and none holds, it is denied; with none, it is allowed.
// An obligation is shown, never enforced. Every refusal is kept as a decision, and so is every use a permission
// counts. Changed: Heratio checked these policies on the record's page and its print; here they guard the record's
// files in the public catalogue (use, and reproduce when downloaded), and they only ever narrow what the Workspace's
// own access, embargoes and rights already allow. Who may change the record is not held by them, as Heratio's
// administrators were not.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\ResearchAccessDecision;
use App\Models\ResearchRightsPolicy;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RightsPolicies
{
    public function save(User $actor, RecordResource $record, array $data, ?ResearchRightsPolicy $policy = null): ResearchRightsPolicy
    {
        if ($record->institution_id !== $actor->institution_id || ! $actor->may('update', 'description', $record)) {
            throw new InvalidArgumentException(__('You may not change that record.'));
        }
        $emails = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($data['accounts'] ?? ''))));
        $accounts = $emails ? User::where('institution_id', $actor->institution_id)->whereIn(DB::raw('lower(email)'), array_map('mb_strtolower', $emails))->pluck('id')->all() : [];
        if (count($accounts) !== count(array_unique(array_map('mb_strtolower', $emails)))) {
            throw new InvalidArgumentException(__('Name accounts of the institution by their e-mail addresses.'));
        }
        $from = ($data['date_from'] ?? '') ?: null;
        $to = ($data['date_to'] ?? '') ?: null;
        if ($from && $to && $to < $from) {
            throw new InvalidArgumentException(__('The end date comes before the start.'));
        }
        $constraints = array_filter(['account_ids' => $accounts ?: null, 'date_from' => $from, 'date_to' => $to, 'max_uses' => ($data['max_uses'] ?? '') !== '' && $data['max_uses'] !== null ? max(1, (int) $data['max_uses']) : null]);
        $fields = ['policy_type_id' => $this->concept('odrl-policy-type', (string) ($data['policy_type'] ?? 'permission')), 'action_id' => $this->concept('odrl-action', (string) ($data['action'] ?? 'use')),
            'constraints' => $constraints ?: null, 'note' => trim((string) ($data['note'] ?? '')) ?: null];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $policy ? tap($policy)->update($fields)
            : ResearchRightsPolicy::create($fields + ['institution_id' => $record->institution_id, 'record_resource_id' => $record->id, 'created_by_id' => $actor->id])));
    }

    public function delete(User $actor, ResearchRightsPolicy $policy): void
    {
        if (! $actor->may('update', 'description', $policy->record)) {
            throw new InvalidArgumentException(__('You may not change that record.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $policy->delete()));
    }

    /** Whether the user (or the public, with none) may do the action to the record: [permitted, rationale, policy]. */
    public function evaluate(?User $user, RecordResource $record, string $action): array
    {
        if ($user && $user->institution_id === $record->institution_id && $user->may('update', 'description', $record)) {
            return [true, __('Who may change the record is not held by its rights policies.'), null];
        }
        $actionId = $this->concept('odrl-action', $action);
        $policies = ResearchRightsPolicy::where(['record_resource_id' => $record->id, 'action_id' => $actionId])->with('policyType')->get();
        if ($policies->isEmpty()) {
            return [true, __('No policy covers it.'), null];
        }
        $holds = fn ($p) => $this->holds($p, $user);
        if ($deny = $policies->first(fn ($p) => $p->policyType->notation === 'prohibition' && $holds($p))) {
            $result = [false, __(':action is prohibited by a rights policy of the record.', ['action' => ucfirst($action)]), $deny];
        } elseif ($allow = $policies->first(fn ($p) => $p->policyType->notation === 'permission' && $holds($p))) {
            $result = [true, __(':action is permitted by a rights policy of the record.', ['action' => ucfirst($action)]), $allow];
        } elseif ($policies->contains(fn ($p) => $p->policyType->notation === 'permission')) {
            $result = [false, __('The record\'s rights policies permit :action to named people only.', ['action' => $action]), null];
        } else {
            return [true, __('No permission or prohibition holds.'), null];
        }
        // A refusal is kept; so is a use a permission with a most number of uses counts.
        if (! $result[0] || isset($result[2]?->constraints['max_uses'])) {
            ResearchAccessDecision::create(['policy_id' => $result[2]?->id, 'user_id' => $user?->id, 'record_resource_id' => $record->id, 'action_id' => $actionId,
                'permitted' => $result[0], 'rationale' => $result[1], 'evaluated_at' => now()]);
        }

        return $result;
    }

    private function holds(ResearchRightsPolicy $p, ?User $user): bool
    {
        $c = (array) $p->constraints;
        $today = now(config('workspace.timezone'))->toDateString();
        if (isset($c['account_ids']) && (! $user || ! in_array($user->id, $c['account_ids'], true))) {
            return false;
        }
        if (! empty($c['date_from']) && $today < $c['date_from'] || ! empty($c['date_to']) && $today > $c['date_to']) {
            return false;
        }

        return empty($c['max_uses']) || ($user && ResearchAccessDecision::where(['policy_id' => $p->id, 'user_id' => $user->id, 'permitted' => true])->count() < $c['max_uses']);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
