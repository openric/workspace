<?php

// OpenRiC Workspace - limits on what researchers download and keep (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchQuotaService. A policy limits downloads (per calendar month or over all time) and the bytes
// a researcher keeps in research workspaces, for everyone, a kind of researcher, one researcher or one project. For
// each limit the narrowest policy that sets it wins: the researcher's, then the project's, then their kind's, then
// everyone's. Past the soft mark (80% unless set) the researcher is warned; at the limit the download or upload is
// refused. Counted downloads: project exports, replication packs and research workspace files, as Heratio counts
// them. Changed: Heratio counted downloads from its activity log; here they have their own log, so that no other
// activity can be mistaken for one. Who runs the research services sets the policies and sees what each researcher
// has used.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\Researcher;
use App\Models\ResearchProject;
use App\Models\ResearchQuotaPolicy;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Quotas
{
    private const PRECEDENCE = ['user', 'project', 'role', 'global'];

    /** The limits that hold for the user (and project): [max_downloads, max_storage_bytes, soft_warn_pct, period, sources]. */
    public function limits(User $user, ?ResearchProject $project = null): array
    {
        $kind = Researcher::where(['institution_id' => $user->institution_id, 'user_id' => $user->id])->value('researcher_type_id');
        $keys = ['user' => $user->id, 'project' => $project?->id, 'role' => $kind, 'global' => null];
        $policies = ResearchQuotaPolicy::where(['institution_id' => $user->institution_id, 'is_active' => true])->with('scope', 'period')->get();
        $out = ['max_downloads' => null, 'max_storage_bytes' => null, 'soft_warn_pct' => 80, 'period' => 'monthly', 'sources' => []];
        foreach (self::PRECEDENCE as $scope) {
            if ($scope !== 'global' && ! $keys[$scope]) {
                continue;
            }
            foreach ($policies->filter(fn ($p) => $p->scope->notation === $scope && $p->scope_key === $keys[$scope]) as $p) {
                if ($out['max_downloads'] === null && $p->max_downloads !== null) {
                    $out = ['max_downloads' => $p->max_downloads, 'soft_warn_pct' => $p->soft_warn_pct, 'period' => $p->period->notation] + $out;
                    $out['sources']['downloads'] = $scope;
                }
                if ($out['max_storage_bytes'] === null && $p->max_storage_bytes !== null) {
                    $out['max_storage_bytes'] = $p->max_storage_bytes;
                    $out['sources']['storage'] = $scope;
                }
            }
        }

        return $out;
    }

    public function downloads(User $user, string $period): int
    {
        return DB::table('research_downloads')->where('user_id', $user->id)
            ->when($period === 'monthly', fn ($q) => $q->where('created_at', '>=', now(config('workspace.timezone'))->startOfMonth()))->count();
    }

    /** The bytes the user keeps in research workspaces. */
    public function storage(User $user): int
    {
        return (int) DB::table('research_workspace_files as w')->join('research_files as f', 'f.id', '=', 'w.file_id')->where('w.added_by_id', $user->id)->sum('f.size_bytes');
    }

    /**
     * Whether one more download, or $bytes more kept, is allowed: refused with an explanation at the limit; a warning,
     * or null, below it.
     */
    public function check(User $user, string $what, ?ResearchProject $project = null, int $bytes = 0): ?string
    {
        $limits = $this->limits($user, $project);
        [$used, $max, $add] = $what === 'download' ? [$this->downloads($user, $limits['period']), $limits['max_downloads'], 1] : [$this->storage($user), $limits['max_storage_bytes'], $bytes];
        if ($max === null) {
            return null;
        }
        if ($used + $add > $max) {
            throw new InvalidArgumentException($what === 'download'
                ? __('You have reached your download limit of :max:period. Ask the reading room if you need more.', ['max' => $max, 'period' => $limits['period'] === 'monthly' ? ' '.__('this month') : ''])
                : __('That would take you past your storage limit of :max. Remove files you no longer need, or ask the reading room.', ['max' => self::bytes($max)]));
        }

        return $max > 0 && ($used + $add) * 100 >= $max * $limits['soft_warn_pct']
            ? __('You have used :used of your :what limit of :max.', ['used' => $what === 'download' ? $used + $add : self::bytes($used + $add), 'what' => $what === 'download' ? __('download') : __('storage'),
                'max' => $what === 'download' ? $max : self::bytes($max)])
            : null;
    }

    /** Check, then count the download; the warning, if any. */
    public function download(User $user, ?ResearchProject $project, ?Model $entity, string $title): ?string
    {
        $warning = $this->check($user, 'download', $project);
        DB::table('research_downloads')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $user->institution_id, 'user_id' => $user->id, 'project_id' => $project?->id,
            'entity_table' => $entity?->getTable(), 'entity_id' => $entity?->getKey(), 'entity_title' => mb_substr($title, 0, 500), 'created_at' => now()]);

        return $warning;
    }

    public function save(User $actor, array $data, ?ResearchQuotaPolicy $policy = null): ResearchQuotaPolicy
    {
        $scope = (string) ($data['scope'] ?? 'global');
        $key = $scope === 'global' ? null : trim((string) ($data['scope_key'] ?? ''));
        if ($scope !== 'global' && ! $this->keyExists($actor, $scope, (string) $key)) {
            throw new InvalidArgumentException(__('Choose whom the limit is for.'));
        }
        $max = fn (string $k) => ($data[$k] ?? '') === '' || $data[$k] === null ? null : max(0, (int) $data[$k]);
        $fields = ['scope_id' => $this->concept('quota-scope', $scope), 'scope_key' => $key, 'period_id' => $this->concept('quota-period', (string) ($data['period'] ?? 'monthly')),
            'max_downloads' => $max('max_downloads'), 'max_storage_bytes' => ($data['max_storage_mb'] ?? '') === '' || $data['max_storage_mb'] === null ? null : max(0, (int) $data['max_storage_mb']) * 1024 * 1024,
            'soft_warn_pct' => max(1, min(100, (int) ($data['soft_warn_pct'] ?? 80))), 'is_active' => ! empty($data['is_active']), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null];
        $clash = ResearchQuotaPolicy::where(['institution_id' => $actor->institution_id, 'scope_id' => $fields['scope_id'], 'scope_key' => $key, 'period_id' => $fields['period_id']])
            ->when($policy, fn ($q) => $q->whereKeyNot($policy->id))->exists();
        if ($clash) {
            throw new InvalidArgumentException(__('There is a limit for them and that period already; change that one.'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $policy ? tap($policy)->update($fields) : ResearchQuotaPolicy::create($fields + ['institution_id' => $actor->institution_id])));
    }

    public function delete(User $actor, ResearchQuotaPolicy $policy): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $policy->delete()));
    }

    /** Each researcher's use against their limits (Heratio's usageReport). */
    public function usage(User $actor): Collection
    {
        return User::where('institution_id', $actor->institution_id)->whereIn('id', DB::table('research_downloads')->select('user_id')->union(DB::table('research_workspace_files')->select('added_by_id')))
            ->orderBy('name')->get()->map(function ($u) {
                $limits = $this->limits($u);

                return ['user' => $u, 'downloads' => $this->downloads($u, $limits['period']), 'storage' => $this->storage($u), 'limits' => $limits];
            });
    }

    public static function bytes(int $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 1).' GB' : ($bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB');
    }

    private function keyExists(User $actor, string $scope, string $key): bool
    {
        return $key !== '' && match ($scope) {
            'user' => User::where('institution_id', $actor->institution_id)->whereKey($key)->exists(),
            'project' => ResearchProject::where('institution_id', $actor->institution_id)->whereKey($key)->exists(),
            'role' => DB::table('researcher_types')->where('institution_id', $actor->institution_id)->where('id', $key)->exists(),
            default => false,
        };
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
