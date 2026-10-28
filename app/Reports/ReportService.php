<?php

// OpenRiC Workspace - reports and statistics (roadmap phase 8), after Heratio's ahg-reports: ReportService (getReportStats,
// and the descriptions, authorities, repositories, updates, user activity and taxonomies reports, with their date filters,
// pages and CSV), CollectionsHealthService (publication and digital coverage), CatalogueGrowthController (growth by month,
// holdings by level and by repository) and DataQualityController (ISAD(G) completeness). Their AtoM queries are rewritten
// for the Workspace's tables; what the Workspace does not have (accessions, donors, AtoM's physical object) has no report.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Reports;

use App\Description\Elements;
use App\Models\Concept;
use App\Models\Name;
use App\Models\User;
use App\Models\Vocabulary;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ReportService
{
    /** list => the record kind (ADR 0005) its reader must be able to read, or the account permission it needs. */
    public const LISTS = [
        'descriptions' => 'description', 'authorities' => 'authority', 'repositories' => 'repository',
        'updates' => 'description', 'activity' => 'view-audit-log', 'taxonomies' => null,
    ];

    /** The kinds counted, and listed among the updates. */
    private const KINDS = ['record', 'agent', 'repository', 'activity', 'place', 'container', 'instantiation'];

    public static function mayRead(User $user, string $list): bool
    {
        $permission = self::LISTS[$list];

        return match ($permission) {
            null => true,
            'view-audit-log' => $user->can(Vocab::permission($permission)),
            default => $user->mayAnywhere('read', $permission),
        };
    }

    /**
     * The overview: how many of each kind, how many are published, how many units have a digital file,
     * what was changed this week, growth by month, and the holdings by level and by repository.
     */
    public function overview(string $institutionId): array
    {
        $counts = [];
        foreach (self::KINDS as $kind) {
            $query = $this->live($kind, $institutionId);
            $counts[$kind] = ['total' => $query->count(), 'published' => $kind === 'instantiation' ? null : (clone $query)->whereIn('id', $this->published($kind))->count()];
        }
        $records = $this->live('record', $institutionId);
        $month = "to_char(date_trunc('month', created_at), 'YYYY-MM')";
        $growth = collect(['record', 'agent'])->mapWithKeys(fn ($kind) => [$kind => $this->live($kind, $institutionId)->where('created_at', '>=', now()->startOfMonth()->subMonths(11))
            ->toBase()->groupByRaw($month)->selectRaw("$month as month, count(*) as n")->pluck('n', 'month')]);
        $levels = (clone $records)->toBase()->groupBy('level_id')->selectRaw('level_id, count(*) as n')->pluck('n', 'level_id');
        $holders = (clone $records)->toBase()->groupBy('repository_id')->selectRaw('repository_id, count(*) as n')->pluck('n', 'repository_id');

        return [
            'counts' => $counts,
            'digital' => (clone $records)->whereIn('id', DB::table('instantiations as i')->join('digital_files as f', 'f.instantiation_id', '=', 'i.id')->whereNull('i.deleted_at')->select('i.record_resource_id'))->count(),
            'week' => (clone $records)->where('updated_at', '>=', now()->subDays(7))->count(),
            'months' => collect(range(11, 0))->map(fn ($back) => now()->startOfMonth()->subMonths($back)->format('Y-m'))
                ->mapWithKeys(fn ($m) => [$m => ['record' => (int) ($growth['record'][$m] ?? 0), 'agent' => (int) ($growth['agent'][$m] ?? 0)]]),
            'levels' => Concept::with('labels')->findMany($levels->keys())->map(fn ($c) => ['concept' => $c, 'count' => (int) $levels[$c->id]])->sortByDesc('count')->values(),
            'holders' => $this->live('repository', $institutionId)->with(EntityKinds::get('repository')['with'])->findMany($holders->keys())
                ->map(fn ($r) => ['repository' => $r, 'count' => (int) $holders[$r->id]])->sortByDesc('count')->values(),
        ];
    }

    /**
     * Data quality: of the units of description, how many have each ISAD(G) element in some language,
     * with the reference code, dates and creator, which are kept apart from the values.
     *
     * @return array{total: int, elements: Collection<int, array{label: string, number: ?string, present: int}>}
     */
    public function quality(string $institutionId): array
    {
        $ids = $this->live('record', $institutionId)->select('id');
        $count = fn (QueryBuilder $q) => $q->whereIn('subject_id', clone $ids)->distinct()->count('subject_id');
        $lang = Vocab::languages();
        $elements = Elements::of(Elements::ISAD_G)->map(function (Concept $element) use ($count, $lang) {
            $type = Elements::nameType($element->notation);
            $values = $type
                ? DB::table('names')->where('subject_table', 'record_resources')->whereIn('name_type_id', Concept::where('iri', Vocab::NAME_TYPES.$type)->select('id'))
                : DB::table('description_values')->where('subject_table', 'record_resources')->where('element_id', $element->id);

            return ['label' => $element->label($lang), 'number' => Elements::number($element), 'present' => $count($values)];
        });
        $related = DB::table('relations')->whereIn('relation_type_id', Concept::whereIn('iri', [Vocab::BASE.'relation-type/creation', Vocab::BASE.'relation-type/accumulation'])->select('id'));
        $kept = [
            ['label' => __('Reference code'), 'number' => '3.1.1', 'present' => $count(DB::table('identifiers')->where('subject_table', 'record_resources')
                ->whereIn('identifier_type_id', Concept::where('iri', Vocab::IDENTIFIER_TYPES.'local')->select('id')))],
            ['label' => __('Dates'), 'number' => '3.1.3', 'present' => $count(DB::table('dates')->where('subject_table', 'record_resources'))],
            ['label' => __('Name of creator'), 'number' => '3.2.1', 'present' => $this->live('record', $institutionId)
                ->where(fn ($q) => $q->whereIn('id', (clone $related)->where('source_table', 'record_resources')->select('source_id'))
                    ->orWhereIn('id', (clone $related)->where('target_table', 'record_resources')->select('target_id')))->count()],
        ];

        return ['total' => $this->live('record', $institutionId)->count(),
            'elements' => $elements->concat($kept)->sortBy(fn ($e) => $e['number'] ?? '9', SORT_NATURAL)->values()];
    }

    /**
     * One list, filtered: from and to (dates, inclusive), of (created or updated), level, type (agent type) and
     * published (yes or no). An Eloquent or query builder, for a page on screen or every row for CSV.
     */
    public function query(User $user, string $list, array $filters): Builder|QueryBuilder|Collection
    {
        $of = ($filters['of'] ?? '') === 'updated' ? 'updated_at' : 'created_at';
        $dated = fn ($q, ?string $column = null) => $q
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where($column ?? $of, '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where($column ?? $of, '<=', $to.' 23:59:59'));
        $published = fn ($q, string $kind) => $q->when($filters['published'] ?? null, fn ($q, $yes) => $yes === 'yes'
            ? $q->whereIn('id', $this->published($kind)) : $q->whereNotIn('id', $this->published($kind)));
        $institution = $user->institution_id;

        return match ($list) {
            'descriptions' => $published($dated($this->live('record', $institution)), 'record')
                ->when($filters['level'] ?? null, fn ($q, $level) => $q->where('level_id', $level))
                ->with([...EntityKinds::get('record')['with'], 'names.state', 'level.labels', 'repository.names.nameType', 'parent'])->orderByDesc($of)->orderBy('id'),
            'authorities' => $published($dated($this->live('agent', $institution)), 'agent')
                ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('agent_type_id', $type))
                ->with([...EntityKinds::get('agent')['with'], 'names.state'])->orderByDesc($of)->orderBy('id'),
            'repositories' => $published($dated($this->live('repository', $institution)), 'repository')
                ->withCount(['records' => fn ($q) => $q->whereNull('deleted_at')])
                ->with([...EntityKinds::get('repository')['with'], 'names.state'])->orderByDesc($of)->orderBy('id'),
            // ponytail: each kind is read whole and merged in PHP; a union in SQL when a catalogue changes by the hundred thousand.
            'updates' => collect(self::KINDS)->filter(fn ($kind) => $user->mayAnywhere('read', EntityKinds::get($kind)['permission']))
                ->flatMap(fn ($kind) => $dated($this->live($kind, $institution), 'updated_at')->with(EntityKinds::get($kind)['with'])->get())
                ->sortByDesc(fn (Model $m) => $m->updated_at->getTimestamp())->values(),
            'activity' => $dated(DB::table('audit_log as a')->join('users as u', 'u.id', '=', 'a.actor_id')->join('concepts as c', 'c.id', '=', 'a.action_id')
                ->where('a.institution_id', $institution), 'a.occurred_at')
                ->groupBy('u.id', 'u.name', 'c.id')->selectRaw('u.id as user_id, u.name, c.id as action_id, count(*) as n, max(a.occurred_at) as latest')
                ->orderBy('u.name')->orderByDesc('n'),
            'taxonomies' => Vocabulary::withCount('concepts')->orderBy('title'),
        };
    }

    /** The heading of a list's CSV. @return list<string> */
    public function header(string $list): array
    {
        return match ($list) {
            'descriptions' => [__('Title'), __('Reference code'), __('Level of description'), __('Repository'), __('Published'), __('Created'), __('Updated'), 'IRI'],
            'authorities' => [__('Authorised form of name'), __('Type of entity'), __('Description identifier'), __('Published'), __('Created'), __('Updated'), 'IRI'],
            'repositories' => [__('Authorised form of name'), __('Identifier'), __('Archival descriptions'), __('Published'), __('Created'), __('Updated'), 'IRI'],
            'updates' => [__('Kind'), __('Name'), __('Updated'), 'IRI'],
            'activity' => [__('User'), __('Action'), __('Count'), __('Latest')],
            'taxonomies' => [__('Name'), __('Terms'), __('Updated'), 'IRI'],
        };
    }

    /** One row of a list, as text. @return list<string> */
    public function row(string $list, object $row): array
    {
        $lang = Vocab::languages();
        $when = fn ($time) => $time ? Carbon::parse($time)->format('Y-m-d H:i') : '';
        $yes = fn (Model $m) => $this->isPublished($m) ? __('Yes') : __('No');

        return match ($list) {
            'descriptions' => [(string) EntityKinds::label($row), (string) $row->referenceCode(), (string) $row->level?->label($lang),
                (string) EntityKinds::label($row->repository), $yes($row), $when($row->created_at), $when($row->updated_at), $row->iri],
            'authorities' => [(string) EntityKinds::label($row), (string) $row->agentType?->label($lang), (string) $row->description_identifier,
                $yes($row), $when($row->created_at), $when($row->updated_at), $row->iri],
            'repositories' => [(string) EntityKinds::label($row), (string) $row->identifier, (string) $row->records_count, $yes($row), $when($row->created_at), $when($row->updated_at), $row->iri],
            'updates' => [__(EntityKinds::noun($row)), (string) EntityKinds::label($row), $when($row->updated_at), $row->iri],
            'activity' => [$row->name, (string) Concept::with('labels')->find($row->action_id)?->label($lang), (string) $row->n, $when($row->latest)],
            'taxonomies' => [$row->title, (string) $row->concepts_count, $when($row->updated_at), $row->iri],
        };
    }

    /** Whether an entity's authorised name is published in some language: what the public catalogue shows. Needs names.nameType and names.state. */
    public function isPublished(Model $entity): bool
    {
        return $entity->names->contains(fn (Name $n) => $n->nameType->iri === Vocab::NAME_TYPES.'authorised' && $n->state->iri === Vocab::valueState('published'));
    }

    /** The live (not deleted) entities of a kind in the institution. */
    private function live(string $kind, string $institutionId): Builder
    {
        return EntityKinds::get($kind)['class']::where('institution_id', $institutionId)->whereNull('deleted_at');
    }

    /** The ids of a kind's entities with a published authorised name, as PublicCatalogue::visible decides. */
    private function published(string $kind): QueryBuilder
    {
        return DB::table('names')->where('subject_table', (new (EntityKinds::get($kind)['class']))->getTable())
            ->whereIn('state_id', Concept::where('iri', Vocab::valueState('published'))->select('id'))
            ->whereIn('name_type_id', Concept::where('iri', Vocab::NAME_TYPES.'authorised')->select('id'))->select('subject_id');
    }
}
