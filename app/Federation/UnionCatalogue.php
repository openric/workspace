<?php

// OpenRiC Workspace - the union catalogue (roadmap phase 16b), after Heratio's ahg-federation UnionCatalogueService and
// UnionHarvestService: an institution's members, itself among them, each opted in; its own published units of description
// put into the union as its self member, of the levels it shares; other members' records fetched from their union
// endpoint, page after page; the union served to others at /union/records as paged JSON; and searched by title.
// Changed from Heratio: a member's records are fetched from its own endpoint, asking for only its own (self=1), so two
// unions that list each other do not copy each other's copies; and only an institution that shares puts its records in.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Federation;

use App\Models\Concept;
use App\Models\FederationShareSetting;
use App\Models\Institution;
use App\Webhooks\Webhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class UnionCatalogue
{
    public const MAX_PER_PAGE = 200;

    /** The institution as a member of its own union, made the first time. */
    public function self(Institution $institution): object
    {
        $self = DB::table('federation_members')->where(['institution_id' => $institution->id, 'is_self' => true])->first();
        if ($self === null) {
            DB::table('federation_members')->insert(['id' => (string) Str::uuid7(), 'institution_id' => $institution->id, 'name' => $institution->name, 'is_self' => true,
                'is_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            $self = DB::table('federation_members')->where(['institution_id' => $institution->id, 'is_self' => true])->first();
        }

        return $self;
    }

    public function saveMember(Institution $institution, array $data, ?string $id = null): string
    {
        $fields = ['name' => trim((string) $data['name']), 'base_url' => ($data['base_url'] ?? null) ? rtrim($data['base_url'], '/') : null, 'contact' => $data['contact'] ?? null,
            'share_scope' => $data['share_scope'] ?? null, 'is_enabled' => (bool) ($data['is_enabled'] ?? false), 'updated_at' => now()];
        if ($id !== null) {
            DB::table('federation_members')->where(['id' => $id, 'institution_id' => $institution->id])->update($fields);

            return $id;
        }
        $id = (string) Str::uuid7();
        DB::table('federation_members')->insert([...$fields, 'id' => $id, 'institution_id' => $institution->id, 'is_self' => false, 'created_at' => now()]);

        return $id;
    }

    /** The institution's published units, as its self member's union records; the number there. Only where it shares. */
    public function publish(Institution $institution): int
    {
        $self = $this->self($institution);
        $share = FederationShareSetting::for($institution->id);
        if (! $share->share_enabled || ! $self->is_enabled) {
            DB::table('federation_union_records')->where('member_id', $self->id)->delete();

            return 0;
        }
        $levels = array_values(array_filter((array) $share->levels));
        $labels = Concept::with('labels')->whereIn('id', DB::table('search_entries')->where(['institution_id' => $institution->id, 'public' => true])->whereNotNull('level_id')->distinct()->pluck('level_id'))
            ->get()->mapWithKeys(fn ($c) => [$c->id => $c->label(['en'])]);
        $repositories = DB::table('search_entries')->where(['institution_id' => $institution->id, 'public' => true, 'subject_table' => 'repositories'])->pluck('title', 'subject_id');
        $kept = [];
        DB::table('search_entries')->where(['search_entries.institution_id' => $institution->id, 'search_entries.public' => true, 'search_entries.subject_table' => 'record_resources'])
            ->when($levels !== [], fn ($q) => $q->whereIn('search_entries.level_id', $levels))
            ->join('record_resources', 'record_resources.id', '=', 'search_entries.subject_id')->whereNull('record_resources.deleted_at')
            ->select('search_entries.*', 'record_resources.iri')->orderBy('search_entries.subject_id')
            ->chunk(500, function ($rows) use ($self, $labels, $repositories, &$kept) {
                DB::table('federation_union_records')->upsert($rows->map(fn ($r) => ['id' => (string) Str::uuid7(), 'member_id' => $self->id, 'record_ref' => $r->iri,
                    'title' => mb_substr((string) $r->title, 0, 1000), 'level' => $labels[$r->level_id] ?? null,
                    'dates' => trim(implode(' - ', array_unique(array_filter([substr((string) $r->earliest, 0, 4), substr((string) $r->latest, 0, 4)])))) ?: null,
                    'repository' => $repositories[$r->repository_id] ?? null, 'url' => $r->iri, 'indexed_at' => now()])->all(),
                    ['member_id', 'record_ref'], ['title', 'level', 'dates', 'repository', 'url', 'indexed_at']);
                array_push($kept, ...$rows->pluck('iri')->all());
            });
        DB::table('federation_union_records')->where('member_id', $self->id)->whereNotIn('record_ref', $kept === [] ? [''] : $kept)->delete();

        return count($kept);
    }

    /** A member's own records, fetched from its union endpoint; the number kept. */
    public function fetch(object $member): int
    {
        (! $member->is_self && $member->base_url && Webhooks::publicUrl($member->base_url)) || throw new RuntimeException(__('Its address is not a public one.'));
        $seen = [];
        for ($page = 1, $last = 1; $page <= $last && $page <= 1000; $page++) {
            $response = Http::timeout(30)->acceptJson()->get($member->base_url.'/union/records', ['self' => 1, 'page' => $page, 'per_page' => self::MAX_PER_PAGE]);
            $response->successful() || throw new RuntimeException(__('The member answered HTTP :status.', ['status' => $response->status()]));
            $last = max(1, (int) $response->json('last_page', 1));
            $rows = collect((array) $response->json('records', []))->filter(fn ($r) => is_array($r) && filled($r['record_ref'] ?? null) && filled($r['title'] ?? null))
                ->map(fn ($r) => ['id' => (string) Str::uuid7(), 'member_id' => $member->id, 'record_ref' => mb_substr((string) $r['record_ref'], 0, 1000), 'title' => mb_substr((string) $r['title'], 0, 1000),
                    'level' => $r['level'] ?? null, 'dates' => $r['dates'] ?? null, 'repository' => $r['repository'] ?? null,
                    'url' => isset($r['url']) && preg_match('#^https?://#', (string) $r['url']) ? $r['url'] : null, 'indexed_at' => now()]);
            if ($rows->isNotEmpty()) {
                DB::table('federation_union_records')->upsert($rows->all(), ['member_id', 'record_ref'], ['title', 'level', 'dates', 'repository', 'url', 'indexed_at']);
                array_push($seen, ...$rows->pluck('record_ref')->all());
            }
        }
        DB::table('federation_union_records')->where('member_id', $member->id)->whereNotIn('record_ref', $seen === [] ? [''] : $seen)->delete();
        DB::table('federation_members')->where('id', $member->id)->update(['last_fetched_at' => now()]);

        return count($seen);
    }

    /** The union, served: the enabled members' records, or only the institution's own with self=1, paged (Heratio's UnionHarvestService::harvest). */
    public function records(Institution $institution, int $page = 1, ?int $perPage = null, bool $selfOnly = false, ?string $from = null): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage ?? 100));
        $page = max(1, $page);
        $members = DB::table('federation_members')->where(['institution_id' => $institution->id, 'is_enabled' => true])->when($selfOnly, fn ($q) => $q->where('is_self', true))->pluck('name', 'id');
        $base = DB::table('federation_union_records')->whereIn('member_id', $members->keys()->all() ?: [''])
            ->when($from && strtotime($from), fn ($q) => $q->where('indexed_at', '>=', date('c', strtotime($from))));
        $total = (clone $base)->count();
        $rows = $base->orderBy('id')->forPage($page, $perPage)->get();

        return ['records' => $rows->map(fn ($r) => ['record_ref' => $r->record_ref, 'title' => $r->title, 'level' => $r->level, 'dates' => $r->dates, 'repository' => $r->repository,
            'url' => $r->url, 'source' => $members[$r->member_id] ?? null, 'indexed_at' => $r->indexed_at])->all(),
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'count' => $rows->count()];
    }

    /** Search the union by title. */
    public function search(Institution $institution, string $query, int $page = 1, int $perPage = 20): array
    {
        $members = DB::table('federation_members')->where(['institution_id' => $institution->id, 'is_enabled' => true])->pluck('name', 'id');
        $base = DB::table('federation_union_records')->whereIn('member_id', $members->keys()->all() ?: [''])
            ->where(fn ($q) => $q->whereRaw("to_tsvector('simple', title) @@ websearch_to_tsquery('simple', ?)", [$query])->orWhere('title', 'ilike', '%'.addcslashes($query, '%_\\').'%'));
        $total = (clone $base)->count();

        return ['total' => $total, 'results' => $base->orderBy('title')->forPage(max(1, $page), $perPage)->get()->map(fn ($r) => [...(array) $r, 'source' => $members[$r->member_id] ?? null])->all()];
    }
}
