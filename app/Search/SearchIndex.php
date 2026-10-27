<?php

// OpenRiC Workspace - the full-text search index (WS-016, roadmap phase 6, ADR 0009).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-search, on PostgreSQL's full-text search instead of
// Elasticsearch. Each entity the catalogue describes has two entries: the
// public one, with only what the public may read (published names and values,
// of an entity the Public role may read), and the staff one, with everything;
// so a search can never show a draft to someone who may not see it. Each entry
// holds two vectors: one without stemming and without accents, for every
// language, and one stemmed in each value's own language where PostgreSQL has
// a configuration for it. Names weigh most, then identifiers, then the values.
// An entity is indexed again when anything it says changes (SearchServiceProvider).

namespace App\Search;

use App\Exchange\ExportView;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Institution;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Relations\EntityKinds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Locale;

final class SearchIndex
{
    /** What is searched, by the key the catalogue uses for it. */
    public const KINDS = ['records' => RecordResource::class, 'agents' => Agent::class, 'activities' => Activity::class, 'places' => Place::class, 'repositories' => Repository::class];

    private const WITH = ['names.nameType', 'names.state', 'descriptionValues.element', 'descriptionValues.state', 'identifiers', 'dates'];

    /** @var array<string, true> subject table:id waiting to be indexed again at the end of the request */
    private static array $pending = [];

    /** @var array<string, string>|null */
    private static ?array $configs = null;

    public static function mark(string $table, string $id): void
    {
        if (in_array($table, array_map(fn ($c) => (new $c)->getTable(), self::KINDS), true)) {
            self::$pending["$table:$id"] = true;
        }
    }

    /** Index again what changed in this request. */
    public function flush(): void
    {
        $pending = array_keys(self::$pending);
        self::$pending = [];
        foreach ($pending as $key) {
            [$table, $id] = explode(':', $key, 2);
            $class = collect(self::KINDS)->first(fn ($c) => (new $c)->getTable() === $table);
            $entity = $class::with(self::WITH)->find($id);
            $entity ? $this->index($entity) : DB::table('search_entries')->where(['subject_table' => $table, 'subject_id' => $id])->delete();
        }
    }

    /** Index every entity of the institution again. @return int how many */
    public function rebuild(Institution $institution): int
    {
        DB::table('search_entries')->where('institution_id', $institution->id)->delete();
        $count = 0;
        foreach (self::KINDS as $class) {
            $class::where('institution_id', $institution->id)->with(self::WITH)->chunkById(200, function ($entities) use (&$count) {
                foreach ($entities as $entity) {
                    $this->index($entity);
                    $count++;
                }
            });
        }

        return $count;
    }

    public function index(Model $entity): void
    {
        DB::transaction(function () use ($entity) {
            DB::table('search_entries')->where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->delete();
            if ($entity->getAttribute('deleted_at') !== null) {
                return;
            }
            $institution = Institution::find($entity->getAttribute('institution_id'));
            $public = new ExportView(null, false, null, $institution);
            if ($public->readable($entity)) {
                $this->write($entity, true, $public->label($entity), fn ($part) => $public->visible($part));
            }
            $this->write($entity, false, EntityKinds::label($entity), fn () => true);
        });
    }

    /** @param  callable(Model): bool  $shows */
    private function write(Model $entity, bool $public, ?string $title, callable $shows): void
    {
        // [text, BCP 47 language or null, weight]
        $parts = [];
        foreach ($entity->names ?? [] as $name) {
            if ($shows($name)) {
                $parts[] = [$name->value, $name->language, 'A'];
            }
        }
        foreach ($entity->identifiers ?? [] as $identifier) {
            $parts[] = [$identifier->value, null, 'B'];
        }
        foreach ($entity->descriptionValues ?? [] as $value) {
            if ($shows($value)) {
                $parts[] = [$value->value, $value->language, 'C'];
            }
        }
        if ($parts === []) {
            return;
        }
        $plain = $stemmed = $bindings = $stemBindings = [];
        foreach ($parts as [$text, $language, $weight]) {
            $plain[] = "setweight(to_tsvector('simple', unaccent(?)), '$weight')";
            $bindings[] = $text;
            $stemmed[] = "setweight(to_tsvector(?::regconfig, unaccent(?)), '$weight')";
            array_push($stemBindings, $this->config($language), $text);
        }
        $dates = collect($entity->dates ?? []);
        DB::insert('INSERT INTO search_entries (id, institution_id, subject_table, subject_id, public, title, body, level_id, repository_id, earliest, latest, plain, stemmed, updated_at)
            VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '.implode(' || ', $plain).', '.implode(' || ', $stemmed).', now())', [
            $entity->getAttribute('institution_id'), $entity->getTable(), $entity->getKey(), $public, (string) ($title ?? ''),
            mb_substr(implode("\n", array_column($parts, 0)), 0, 200000),
            $entity instanceof RecordResource ? $entity->level_id : null, $entity instanceof RecordResource ? $entity->repository_id : null,
            $dates->pluck('earliest')->filter()->min(), $dates->pluck('latest')->filter()->max(),
            ...$bindings, ...$stemBindings,
        ]);
    }

    /** The PostgreSQL text search configuration for a language, from its English name (en: english); simple where there is none. */
    public function config(?string $language): string
    {
        self::$configs ??= collect(DB::select('SELECT cfgname FROM pg_ts_config'))->pluck('cfgname')->flip()->map(fn () => true)->all();
        if ($language === null || ! class_exists(Locale::class)) {
            return 'simple';
        }
        $name = mb_strtolower(Locale::getDisplayLanguage(Locale::getPrimaryLanguage($language) ?? $language, 'en'));

        return isset(self::$configs[$name]) ? $name : 'simple';
    }
}
