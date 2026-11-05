<?php

// OpenRiC Workspace - ways into the collection (roadmap phase 17d1), after Heratio's ahg-semantic-search ThemeService,
// PlaceService, PersonService, GenreService, TimelineService and ExploreCollectionController: the subjects under which
// most published units sit (its themes), the places they are of or about, the people and organisations who made or
// gathered them, their documentary forms (genres), and how they spread over time by century and decade; each a way in,
// with the published units it leads to. Only what a visitor sees counts: the units, agents, places and concepts of the
// public search index, so nothing withheld is counted or named.
// Changed from Heratio: counted from the RiC relations (subject, place, creation, accumulation, documentary form) and
// the units' RiC dates, not AtoM's term and event tables.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Discovery;

use App\Models\Institution;
use App\Support\Vocab;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Explore
{
    /** Each way in: the relation types, which end is the unit, and the table at the other end. */
    public const FACETS = [
        'themes' => ['types' => ['subject'], 'unit' => 'source', 'table' => 'concepts'],
        'places' => ['types' => ['place', 'subject'], 'unit' => 'either', 'table' => 'places'],
        'people' => ['types' => ['creation', 'accumulation'], 'unit' => 'source', 'table' => 'agents'],
        'genres' => ['types' => ['documentary-form'], 'unit' => 'source', 'table' => 'concepts'],
    ];

    public function __construct(private Institution $institution) {}

    /** The ways in of one facet with the most published units, each with its count. @return Collection<int, object{id: string, n: int}> */
    public function top(string $facet, int $limit = 24): Collection
    {
        return $this->pairs($facet)->selectRaw('other_id AS id, count(DISTINCT unit_id) AS n')->groupBy('other_id')->orderByDesc('n')->limit($limit)->get();
    }

    /** The published units one way in leads to, most recently changed first. */
    public function units(string $facet, string $id, int $perPage = 30): LengthAwarePaginator
    {
        $ids = $this->pairs($facet)->where('other_id', $id)->distinct()->pluck('unit_id');

        return DB::table('search_entries')->where(['institution_id' => $this->institution->id, 'public' => true, 'subject_table' => 'record_resources'])
            ->whereIn('subject_id', $ids)->orderBy('title')->paginate($perPage, ['subject_id', 'title', 'body'])->withQueryString();
    }

    /**
     * Published units by century, and within each by decade; and how many have no date.
     *
     * @return array{centuries: array<int, array{n: int, decades: array<int, int>}>, undated: int}
     */
    public function timeline(): array
    {
        $years = DB::table('search_entries as e')->where(['e.institution_id' => $this->institution->id, 'e.public' => true, 'e.subject_table' => 'record_resources'])
            ->selectRaw('extract(year from e.earliest)::int AS y')->pluck('y');
        $centuries = [];
        foreach ($years->filter() as $y) {
            $c = intdiv($y, 100) * 100;
            $d = intdiv($y, 10) * 10;
            $centuries[$c]['n'] = ($centuries[$c]['n'] ?? 0) + 1;
            $centuries[$c]['decades'][$d] = ($centuries[$c]['decades'][$d] ?? 0) + 1;
        }
        ksort($centuries);
        foreach ($centuries as &$century) {
            ksort($century['decades']);
        }

        return ['centuries' => $centuries, 'undated' => $years->filter(fn ($y) => $y === null)->count()];
    }

    /** The published units whose earliest date falls in a decade. */
    public function decade(int $from, int $perPage = 30): LengthAwarePaginator
    {
        return DB::table('search_entries')->where(['institution_id' => $this->institution->id, 'public' => true, 'subject_table' => 'record_resources'])
            ->whereBetween('earliest', [sprintf('%04d-01-01', $from), sprintf('%04d-12-31', $from + 9)])->orderBy('earliest')->orderBy('title')
            ->paginate($perPage, ['subject_id', 'title', 'body'])->withQueryString();
    }

    /** Unit-and-other pairs of a facet, both ends public. */
    private function pairs(string $facet): Builder
    {
        $f = self::FACETS[$facet];
        $types = DB::table('concepts')->whereIn('iri', array_map(fn ($t) => Vocab::BASE.'relation-type/'.$t, $f['types']))->pluck('id');
        $public = fn (string $table) => DB::table('search_entries')->where(['institution_id' => $this->institution->id, 'public' => true, 'subject_table' => $table])->select('subject_id');
        $rejected = DB::table('concepts')->where('iri', Vocab::BASE.'assertion-status/rejected')->value('id');
        $side = function (string $unit, string $other) use ($types, $f, $public, $rejected) {
            $q = DB::table('relations as r')->where('r.institution_id', $this->institution->id)->whereIn('r.relation_type_id', $types)
                ->where("r.{$unit}_table", 'record_resources')->where("r.{$other}_table", $f['table'])
                ->whereIn("r.{$unit}_id", $public('record_resources'))
                ->whereExists(fn ($a) => $a->from('relation_assertions as a')->whereColumn('a.relation_id', 'r.id')->where('a.status_id', '!=', $rejected))
                ->selectRaw("r.{$unit}_id AS unit_id, r.{$other}_id AS other_id");

            // A concept is public when its vocabulary is: a bundled one, or a published local scheme; agents and places by the index.
            return $f['table'] === 'concepts' ? $q->whereIn("r.{$other}_id", DB::table('concepts')->whereNull('deprecated_at')->select('id'))
                : $q->whereIn("r.{$other}_id", $public($f['table']));
        };
        $query = $f['unit'] === 'either' ? $side('source', 'target')->unionAll($side('target', 'source')) : $side($f['unit'], $f['unit'] === 'source' ? 'target' : 'source');

        return DB::query()->fromSub($query, 'p');
    }
}
