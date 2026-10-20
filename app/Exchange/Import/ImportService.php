<?php

// OpenRiC Workspace - import units of description from CSV or EAD: a dry run, then the import itself (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A dry run is the import itself, made in a transaction that is rolled back,
// so what it reports is what importing does, not a guess. Each unit is
// matched first by its persistent IRI, then by its key in the same source
// (import_keys), then, when asked, by its identifier under the same parent;
// otherwise it is created. An empty field never removes a value. Creators,
// subjects, places and names are linked to the entity the file names by IRI,
// or to the one entity of that name; two of that name is ambiguous and not
// linked; none is created where the account may and the import was asked
// to, else left out. Relations an import makes are proposed until a person
// accepts them (ADR 0006). A unit that fails is not imported and is listed,
// with every unit inside it; the others go on. Everything is written through
// the services the screens use, so every rule and the audit log apply.
// ponytail: one transaction for the whole file, holding the audit chain lock
// throughout; a file of many thousands of units wants a queued job in batches.

namespace App\Exchange\Import;

use App\Audit\AuditLog;
use App\Description\AgentService;
use App\Description\DateService;
use App\Description\Edtf;
use App\Description\IdentifierService;
use App\Description\PlaceService;
use App\Description\RecordService;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ImportService
{
    public const FORMATS = ['csv' => 'CSV (AtoM ISAD(G) columns)', 'ead' => 'EAD 2002 or EAD3'];

    private const LEVELS = Vocab::BASE.'level-of-description/';

    private Import $import;

    private User $user;

    private Institution $institution;

    private Report $report;

    /** @var array<string, RecordResource> units by their key in this file */
    private array $byKey = [];

    /** @var array<string, bool> keys of units not imported, so the units inside them are not either */
    private array $failed = [];

    /** @var array<string, ?Model> agents, places and concepts already resolved in this file */
    private array $resolved = [];

    public function __construct(private RecordService $records, private IdentifierService $identifiers, private DateService $dates,
        private RelationService $relations, private AgentService $agents, private PlaceService $places, private ConceptService $concepts) {}

    /** Read the file and import it; a dry run rolls everything back. The report is stored on the import. */
    public function run(Import $import, bool $commit): Report
    {
        $this->import = $import;
        $this->user = $import->user;
        $this->institution = Institution::findOrFail($import->institution_id);
        $this->report = new Report;
        $this->byKey = $this->failed = $this->resolved = [];
        try {
            $drafts = $import->format === 'ead'
                ? app(EadReader::class)->read($import->content, $import->language, $this->report)
                : app(CsvReader::class)->read($import->content, $import->language, $this->report);
        } catch (InvalidArgumentException $e) {
            $this->report->issue('whole file', 'file', $import->file_name, $e->getMessage(), 'rejected');
            $import->update(['report' => $this->report->toArray()]);

            return $this->report;
        }

        try {
            DB::transaction(function () use ($drafts, $commit) {
                AuditLog::actingAs($this->user, function () use ($drafts) {
                    foreach ($drafts as $draft) {
                        $this->unit($draft);
                    }
                });
                // The database's deferred checks run now, so a dry run meets them too.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                if (! $commit) {
                    throw new DryRun;
                }
            });
        } catch (DryRun) {
            // Rolled back: units created in a dry run do not exist.
            foreach ($this->report->units as &$unit) {
                if ($unit['action'] === 'created') {
                    $unit['unit_id'] = null;
                }
            }
            unset($unit);
        }

        $report = $this->report->toArray();
        if ($commit) {
            $report['reconciliation'] = $this->reconcile();
        }
        $import->update(['report' => $report, 'committed_at' => $commit ? now() : null]);

        return $this->report;
    }

    private function unit(UnitDraft $draft): void
    {
        if ($draft->parentKey !== null && isset($this->failed[$draft->parentKey])) {
            $this->failed[$draft->key] = true;
            $this->report->unit($draft, 'rejected', null, 'the unit it is inside was not imported');

            return;
        }
        $before = $this->seq();
        [$resolved, $counts, $issues] = [$this->resolved, $this->report->counts, count($this->report->issues)];
        try {
            $unit = DB::transaction(fn () => $this->write($draft));
        } catch (InvalidArgumentException|QueryException|RuntimeException $e) {
            // Rolled back to before this unit: what it created does not exist, so forget it.
            $this->resolved = $resolved;
            $this->report->counts = $counts;
            $this->report->issues = [...array_slice($this->report->issues, 0, $issues),
                ...array_values(array_filter(array_slice($this->report->issues, $issues), fn ($i) => $i['kind'] !== 'created'))];
            $this->failed[$draft->key] = true;
            $this->report->unit($draft, 'rejected', null, $e instanceof QueryException ? 'the database refused it: '.$this->reason($e) : $e->getMessage());

            return;
        }
        $this->byKey[$draft->key] = $unit;
        $this->report->unit($draft, $unit->wasRecentlyCreated ? 'created' : ($this->seq() !== $before ? 'updated' : 'unchanged'), $unit->id);
    }

    private function write(UnitDraft $draft): RecordResource
    {
        $parent = $this->parent($draft);
        $unit = $this->match($draft, $parent);
        $language = $draft->language($this->import->language);
        $values = $draft->values;
        $levelIri = $this->level($draft, $unit === null);

        if ($unit === null) {
            $holder = $parent?->repository ?? Repository::findOrFail($this->import->repository_id);
            if (! $this->user->may('create', 'description', $parent ?? $holder)) {
                throw new InvalidArgumentException('you may not create units of description '.($parent ? 'inside that unit' : 'in that repository'));
            }
            $title = $values[$language]['title'] ?? throw new InvalidArgumentException('it has no title in '.$language.', and a new unit needs one');
            $unit = $this->records->create($this->institution, $levelIri, $title, $language, $parent, $parent ? null : $holder, $draft->identifier, $this->user);
            $unit->wasRecentlyCreated = true;
        } else {
            if (! $this->user->may('update', 'description', $unit)) {
                throw new InvalidArgumentException('it matches '.$unit->iri.', which you may not update');
            }
            $unit->wasRecentlyCreated = false;
            if (($draft->identifier !== null && $draft->identifier !== $unit->identifier) || ($levelIri !== null && $levelIri !== $unit->level->iri)) {
                $this->records->updateIdentity($unit, $draft->identifier ?? $unit->identifier, $levelIri ?? $unit->level->iri, $this->user);
            }
        }
        if ($draft->repositoryName !== null && $parent === null && mb_strtolower($draft->repositoryName) !== mb_strtolower((string) EntityKinds::label($unit->repository))) {
            $this->report->issue($draft->location, 'repository', $draft->repositoryName, 'imported into '.EntityKinds::label($unit->repository).', the repository chosen for the import', 'note');
        }
        foreach ($values as $valueLanguage => $byElement) {
            $this->records->describe($unit, $byElement, $valueLanguage, $this->user);
            $this->report->counts['values'] += count($byElement);
        }
        $this->otherIdentifiers($draft, $unit);
        $this->control($draft, $unit);
        $this->unitDates($draft, $unit);
        $this->links($draft, $unit, $language);
        DB::table('import_keys')->upsert([['id' => (string) Str::uuid7(), 'institution_id' => $this->institution->id, 'source' => $this->import->source,
            'key' => $draft->key, 'record_resource_id' => $unit->id, 'import_id' => $this->import->id, 'created_at' => now(), 'updated_at' => now()]],
            ['institution_id', 'source', 'key'], ['record_resource_id', 'import_id', 'updated_at']);

        return $unit;
    }

    private function parent(UnitDraft $draft): ?RecordResource
    {
        if ($draft->parentKey !== null) {
            return $this->byKey[$draft->parentKey] ?? $this->keyed($draft->parentKey)
                ?? throw new InvalidArgumentException('the unit it is inside ('.$draft->parentKey.') is not earlier in the file nor imported before from this source');
        }
        if ($draft->parentIri !== null && ($found = $this->existing(RecordResource::where('iri', $draft->parentIri)))) {
            return $found;
        }

        return $this->import->parent_id ? RecordResource::find($this->import->parent_id) : null;
    }

    /** The unit this one already is: by persistent IRI, by its key in this source, or by identifier under the same parent. */
    private function match(UnitDraft $draft, ?RecordResource $parent): ?RecordResource
    {
        if ($draft->iri !== null && ($unit = $this->existing(RecordResource::where('iri', $draft->iri)))) {
            return $unit;
        }
        if ($unit = $this->keyed($draft->key)) {
            return $unit;
        }
        if (($this->import->options['match_identifier'] ?? false) && $draft->identifier !== null) {
            $same = RecordResource::where('institution_id', $this->institution->id)->whereNull('deleted_at')->where('parent_id', $parent?->id)
                ->when($parent === null, fn ($q) => $q->where('repository_id', $this->import->repository_id))
                ->whereIn('id', DB::table('identifiers')->where(['subject_table' => 'record_resources', 'value' => $draft->identifier])->select('subject_id'))->get();
            if ($same->count() > 1) {
                $this->report->issue($draft->location, 'identifier', $draft->identifier, $same->count().' units have this identifier there; a new unit is made', 'ambiguous');
            }

            return $same->count() === 1 ? $same->first() : null;
        }

        return null;
    }

    private function keyed(string $key): ?RecordResource
    {
        $id = DB::table('import_keys')->where(['institution_id' => $this->institution->id, 'source' => $this->import->source, 'key' => $key])->value('record_resource_id');

        return $id ? $this->existing(RecordResource::whereKey($id)) : null;
    }

    private function existing($query): ?RecordResource
    {
        return $query->where('institution_id', $this->institution->id)->whereNull('deleted_at')->first();
    }

    /** The level the file gives, by EAD level, notation or label in any language; null to keep it, or a failure for a new unit. */
    private function level(UnitDraft $draft, bool $new): ?string
    {
        if ($draft->level === null) {
            if ($new) {
                throw new InvalidArgumentException('it has no level of description, and a new unit needs one');
            }

            return null;
        }
        $wanted = mb_strtolower($draft->level);
        $levels = Concept::choices(self::LEVELS)->get();
        $level = $levels->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted)
            ?? $levels->first(fn (Concept $c) => $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted));
        if ($level === null) {
            if ($new) {
                throw new InvalidArgumentException('"'.$draft->level.'" is not a level of description the Workspace knows');
            }
            $this->report->issue($draft->location, 'level', $draft->level, 'not a level of description the Workspace knows; the level is kept', 'left-out');
        }

        return $level?->iri;
    }

    private function otherIdentifiers(UnitDraft $draft, RecordResource $unit): void
    {
        $types = Concept::choices(Vocab::IDENTIFIER_TYPES)->get();
        foreach ($draft->identifiers as $identifier) {
            $wanted = mb_strtolower($identifier['type']);
            $type = $types->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted || $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted));
            if ($type === null) {
                $this->report->issue($draft->location, 'identifier type', $identifier['type'], 'not an identifier type the Workspace knows; kept as another identifier', 'note');
            }
            $notation = $type?->notation ?? 'other';
            if ($notation === 'local') {
                continue;
            }
            $unit->load('identifiers.identifierType');
            if (! $unit->identifiers->contains(fn ($i) => $i->identifierType->notation === $notation && $i->value === $identifier['value'])) {
                $this->identifiers->add($unit, ['type' => $notation, 'value' => $identifier['value']], $this->user);
            }
        }
    }

    private function control(UnitDraft $draft, RecordResource $unit): void
    {
        if ($draft->descriptionIdentifier === null && $draft->status === null && $draft->detail === null) {
            return;
        }
        $concept = function (?string $label, string $vocabulary, string $field) use ($draft) {
            if ($label === null) {
                return false;
            }
            $wanted = mb_strtolower($label);
            $found = Concept::choices($vocabulary)->get()->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted
                || $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted));
            if ($found === null) {
                $this->report->issue($draft->location, $field, $label, 'not a value the Workspace knows; left as it was', 'left-out');

                return false;
            }

            return $found->iri;
        };
        $status = $concept($draft->status, Vocab::DESCRIPTION_STATUSES, 'descriptionStatus');
        $detail = $concept($draft->detail, Vocab::DESCRIPTION_DETAILS, 'levelOfDetail');
        $this->records->updateControl($unit, $draft->descriptionIdentifier ?? $unit->description_identifier,
            $status === false ? $unit->descriptionStatus?->iri : $status, $detail === false ? $unit->descriptionDetail?->iri : $detail, $this->user);
    }

    private function unitDates(UnitDraft $draft, RecordResource $unit): void
    {
        foreach ($draft->dates as $date) {
            $unit->load('dates.property');
            // The same date is the same span, however it is written: 1950-06 and 1950-06-01/1950-06-30 are one.
            $bounds = $date['normalized'] !== null ? Edtf::bounds($date['normalized']) : null;
            $same = $unit->dates->contains(fn ($d) => $d->property->notation === $date['property']
                && ($date['normalized'] === null ? $d->normalized_value === null
                    : ($d->normalized_value === $date['normalized'] || ($bounds !== null && [$d->earliest?->format('Y-m-d'), $d->latest?->format('Y-m-d')] === $bounds)))
                // A date with no written form is exported with its EDTF as the text; that text is not a new written form.
                && ($date['expressed'] === null || $d->expressed === $date['expressed'] || ($d->expressed === null && $date['expressed'] === $d->normalized_value)));
            if ($same) {
                continue;
            }
            try {
                $this->dates->add($unit, ['property' => $date['property'], 'normalized' => $date['normalized'], 'expressed' => $date['expressed'],
                    'language' => $date['expressed'] ? $date['language'] : null], $this->user);
            } catch (InvalidArgumentException $e) {
                $this->report->issue($draft->location, 'date', $date['normalized'] ?? $date['expressed'], $e->getMessage().'; left out', 'left-out');
            }
        }
    }

    /** Creators, access points and associated units, each as a relation the import proposes. */
    private function links(UnitDraft $draft, RecordResource $unit, string $language): void
    {
        foreach ($draft->creators as $creator) {
            if ($agent = $this->agent($draft, $creator['name'], $creator['type'], $creator['iri'], $language, $creator['history'], 'creator')) {
                $this->relate($draft, $unit, $creator['relation'], $agent);
            }
        }
        foreach ($draft->names as $name) {
            if ($agent = $this->agent($draft, $name['name'], $name['type'], $name['iri'], $language, null, 'name access point')) {
                $this->relate($draft, $unit, 'subject', $agent);
            }
        }
        foreach ($draft->subjects as $subject) {
            if ($concept = $this->subject($draft, $subject['name'], $subject['iri'], $language)) {
                $this->relate($draft, $unit, 'subject', $concept);
            }
        }
        foreach ($draft->places as $place) {
            if ($found = $this->place($draft, $place['name'], $place['iri'], $language)) {
                $this->relate($draft, $found, 'place', $unit);
            }
        }
        foreach ($draft->associated as $iri) {
            $other = $this->existing(RecordResource::where('iri', $iri)) ?? collect($this->byKey)->first(fn ($u) => $u->iri === $iri);
            if ($other === null) {
                // A link to a unit later in the same file is made when that unit is read.
                $this->report->issue($draft->location, 'related unit', $iri, 'no unit of description with this IRI is in the Workspace yet; not linked', 'left-out');
            } elseif ($other->id !== $unit->id) {
                $this->relate($draft, $unit, 'associated-record', $other);
            }
        }
    }

    private function relate(UnitDraft $draft, Model $source, string $type, Model $target): void
    {
        $exists = Relation::where(['source_table' => $source->getTable(), 'source_id' => $source->getKey(), 'target_table' => $target->getTable(), 'target_id' => $target->getKey()])
            ->whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/'.$type))->exists()
            || (config('workspace.relation_types.'.$type)['symmetric'] ?? false) && Relation::where(['source_table' => $target->getTable(), 'source_id' => $target->getKey(),
                'target_table' => $source->getTable(), 'target_id' => $source->getKey()])->whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/'.$type))->exists();
        if ($exists) {
            return;
        }
        try {
            $this->relations->create($source, $type, $target, [], null, ['origin' => 'import', 'label' => $this->import->source]);
            $this->report->counts['relations']++;
        } catch (InvalidArgumentException $e) {
            $this->report->issue($draft->location, $type, EntityKinds::label($target), $e->getMessage().'; not linked', 'left-out');
        }
    }

    /** An agent by the IRI the file gives, else the one agent of that authorised name, else a new one where allowed. */
    private function agent(UnitDraft $draft, string $name, ?string $type, ?string $iri, string $language, ?string $history, string $field): ?Model
    {
        if ($iri !== null) {
            $found = Agent::where('iri', $iri)->where('institution_id', $this->institution->id)->whereNull('deleted_at')->first()
                ?? Repository::where('iri', $iri)->where('institution_id', $this->institution->id)->whereNull('deleted_at')->first();
            if ($found && EntityKinds::readable($this->user, $found)) {
                return $found->mergedInto ?? $found;
            }
        }
        $cacheKey = 'agent:'.mb_strtolower($name).':'.$type;
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        $matches = Agent::where('institution_id', $this->institution->id)->whereNull('deleted_at')->whereNull('merged_into_id')
            ->whereIn('id', $this->namedQuery('agents', $name))
            ->when($type !== null, fn ($q) => $q->whereHas('agentType', fn ($q) => $q->where('iri', Agent::TYPES.$type)))->get()
            ->filter(fn (Agent $a) => EntityKinds::readable($this->user, $a));
        if ($matches->count() > 1) {
            $this->report->issue($draft->location, $field, $name, $matches->count().' authority records have this name; not linked', 'ambiguous');

            return $this->resolved[$cacheKey] = null;
        }
        if ($matches->count() === 1) {
            return $this->resolved[$cacheKey] = $matches->first();
        }
        $type ??= ($this->import->options['agent_type'] ?? '') ?: null;
        if ($type === null || ! $this->user->may('create', 'authority')) {
            $this->report->issue($draft->location, $field, $name, $type === null ? 'no authority record has this name, and the file does not say what kind of agent it is; not linked'
                : 'no authority record has this name, and you may not create one; not linked', 'left-out');

            return $this->resolved[$cacheKey] = null;
        }
        $agent = $this->agents->create($this->institution, $type, $name, $language, null, $this->user);
        if ($history !== null) {
            $this->agents->describe($agent, ['agent-history' => $history], $language, $this->user);
        }
        $this->report->counts['agents']++;
        $this->report->issue($draft->location, $field, $name, 'a new authority record ('.str_replace('-', ' ', $type).')', 'created');

        return $this->resolved[$cacheKey] = $agent;
    }

    private function subject(UnitDraft $draft, string $label, ?string $iri, string $language): ?Concept
    {
        if ($iri !== null && ($found = Concept::where(['iri' => $iri, 'institution_id' => $this->institution->id])->whereNull('deprecated_at')->first())) {
            return $found;
        }
        $cacheKey = 'subject:'.mb_strtolower($label);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        $matches = Concept::where('institution_id', $this->institution->id)->whereNull('deprecated_at')
            ->whereHas('vocabulary.kind', fn ($q) => $q->where('iri', Vocab::BASE.'concept-scheme-kind/subject'))
            ->whereHas('labels', fn ($q) => $q->whereRaw('lower(pref_label) = ?', [mb_strtolower($label)]))->get();
        if ($matches->count() > 1) {
            $this->report->issue($draft->location, 'subject', $label, $matches->count().' subjects have this label; not linked', 'ambiguous');

            return $this->resolved[$cacheKey] = null;
        }
        if ($matches->count() === 1) {
            return $this->resolved[$cacheKey] = $matches->first();
        }
        if (! ($this->import->options['create_terms'] ?? false) || ! $this->user->may('create', 'vocabulary')) {
            $this->report->issue($draft->location, 'subject', $label, 'no subject has this label; not linked', 'left-out');

            return $this->resolved[$cacheKey] = null;
        }
        $concept = $this->concepts->create($this->concepts->schemeOfKind($this->institution, 'subject', $this->user), ['label' => $label, 'language' => $language], $this->user);
        $this->report->counts['concepts']++;
        $this->report->issue($draft->location, 'subject', $label, 'a new subject', 'created');

        return $this->resolved[$cacheKey] = $concept;
    }

    private function place(UnitDraft $draft, string $name, ?string $iri, string $language): ?Place
    {
        if ($iri !== null && ($found = Place::where(['iri' => $iri, 'institution_id' => $this->institution->id])->whereNull('deleted_at')->first())) {
            return $found;
        }
        $cacheKey = 'place:'.mb_strtolower($name);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        $matches = Place::where('institution_id', $this->institution->id)->whereNull('deleted_at')->whereIn('id', $this->namedQuery('places', $name))->get();
        if ($matches->count() > 1) {
            $this->report->issue($draft->location, 'place', $name, $matches->count().' places have this name; not linked', 'ambiguous');

            return $this->resolved[$cacheKey] = null;
        }
        if ($matches->count() === 1) {
            return $this->resolved[$cacheKey] = $matches->first();
        }
        if (! ($this->import->options['create_terms'] ?? false) || ! $this->user->may('create', 'vocabulary')) {
            $this->report->issue($draft->location, 'place', $name, 'no place has this name; not linked', 'left-out');

            return $this->resolved[$cacheKey] = null;
        }
        $place = $this->places->create($this->institution, $name, $language, null, $this->user);
        $this->report->counts['places']++;
        $this->report->issue($draft->location, 'place', $name, 'a new place', 'created');

        return $this->resolved[$cacheKey] = $place;
    }

    /** Ids of the entities in a table with this authorised name in any language, ignoring case. */
    private function namedQuery(string $table, string $name)
    {
        return Name::where('subject_table', $table)->whereRaw('lower(value) = ?', [mb_strtolower($name)])
            ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->select('subject_id');
    }

    /**
     * After an import: every unit the report says was imported exists, has its title and is keyed to this source;
     * the number of units in the file is the number imported or matched plus the number not imported.
     */
    private function reconcile(): array
    {
        $imported = collect($this->report->units)->where('action', '!=', 'rejected');
        $ids = $imported->pluck('unit_id')->filter()->all();
        $present = RecordResource::whereKey($ids)->whereNull('deleted_at')->whereHas('names', fn ($q) => $q->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised')))->count();
        $keyed = DB::table('import_keys')->where(['institution_id' => $this->institution->id, 'source' => $this->import->source])->whereIn('record_resource_id', $ids)->distinct()->count('record_resource_id');
        $actions = $this->report->actions();

        return [
            'in_file' => count($this->report->units), 'imported_or_matched' => $imported->count(), 'not_imported' => $actions['rejected'],
            'present_with_title' => $present, 'keyed_to_source' => $keyed,
            'holds' => $present === count(array_unique($ids)) && $keyed === count(array_unique($ids)) && $imported->count() + $actions['rejected'] === count($this->report->units),
        ];
    }

    private function seq(): ?int
    {
        return DB::table('audit_log')->max('seq');
    }

    private function reason(QueryException $e): string
    {
        return preg_match('/ERROR:\s+([^\n]+)/', $e->getMessage(), $m) ? $m[1] : 'a database rule';
    }
}
