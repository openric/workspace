<?php

// OpenRiC Workspace - version history of units of description and authority records, with compare and restore (roadmap
// phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-version-control (SnapshotBuilder, VersionWriter, DiffComputer, RestoreService). A version is the
// whole of what the entity says at a moment: its values, names, dates, identifiers and relations, and where it sits.
// Heratio wrote one when the entity's own row was saved; in the Workspace an edit changes values and names, not that
// row, so any write to the entity or to anything of it marks it, as the search index is marked, and one version is
// written for each entity marked when the request, command or job ends, unless nothing changed. Restoring puts back
// the values and names a version had, each under its own id and IRI, as Heratio restored the record's texts: a value
// whose text changes goes back to draft, for review, as an edit does; its dates, identifiers and relations are shown
// in the version but not restored, as in Heratio. A restore is itself a new version, saying which one it restored.

namespace App\Versions;

use App\Audit\AuditLog;
use App\CustomFields\CustomFields;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\DescriptionValue;
use App\Models\EntityVersion;
use App\Models\Name;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class Versions
{
    /** What is versioned, by table. */
    public const KINDS = ['record_resources' => RecordResource::class, 'agents' => Agent::class];

    /** @var array<string, ?string> "table:id" => the account that changed it */
    private static array $pending = [];

    private static bool $paused = false;

    public static function mark(?string $table, ?string $id): void
    {
        if (! self::$paused && $table !== null && $id !== null && isset(self::KINDS[$table])) {
            self::$pending["$table:$id"] ??= auth()->id();
        }
    }

    /** Write a version of each entity marked, once. */
    public function flush(?string $summary = null): void
    {
        $pending = self::$pending;
        self::$pending = [];
        foreach ($pending as $key => $userId) {
            [$table, $id] = explode(':', $key, 2);
            try {
                $entity = self::KINDS[$table]::find($id);
                $entity && $this->write($entity, $userId, $summary);
            } catch (Throwable $e) {
                // A version that cannot be written never undoes the change itself (Heratio's observer did the same).
                report($e);
            }
        }
    }

    /** A version of the entity as it is now, unless it is what the last version already holds. */
    public function write(Model $entity, ?string $userId, ?string $summary = null, ?int $restoredFrom = null): ?EntityVersion
    {
        $snapshot = $this->snapshot($entity);
        $last = EntityVersion::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->orderByDesc('version_number')->first();
        if ($last && $restoredFrom === null && $this->comparable($last->snapshot) === $this->comparable($snapshot)) {
            return null;
        }

        return EntityVersion::create(['institution_id' => $entity->institution_id, 'subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(),
            'version_number' => ($last->version_number ?? 0) + 1, 'snapshot' => $snapshot, 'changed_fields' => $last ? array_keys($this->diff($last->snapshot, $snapshot)) : null,
            'change_summary' => $summary, 'created_by_id' => $userId, 'is_restore' => $restoredFrom !== null, 'restored_from_version' => $restoredFrom, 'created_at' => now()]);
    }

    /** Everything the entity says, keyed so two versions compare field by field. */
    public function snapshot(Model $entity): array
    {
        $entity->load('descriptionValues.element', 'descriptionValues.state', 'names.nameType', 'names.state', 'dates.property', 'identifiers.identifierType');
        $relations = Relation::where(fn ($q) => $q->where(['source_table' => $entity->getTable(), 'source_id' => $entity->getKey()])
            ->orWhere(fn ($q) => $q->where(['target_table' => $entity->getTable(), 'target_id' => $entity->getKey()])))->with('relationType')->get();
        $base = $entity instanceof RecordResource
            ? ['level' => $entity->level?->iri, 'parent' => $entity->parent?->iri, 'repository' => $entity->repository?->iri, 'description_identifier' => $entity->description_identifier]
            : ['type' => $entity->agentType?->iri, 'description_identifier' => $entity->getAttribute('description_identifier')];

        return ['schema_version' => 1, 'iri' => $entity->iri, 'base' => $base,
            'values' => $entity->descriptionValues->sortBy(fn ($v) => [$v->element->notation, $v->language])->map(fn ($v) => ['id' => $v->id, 'element' => $v->element->notation,
                'language' => $v->language, 'value' => $v->value, 'is_original' => (bool) $v->is_original, 'state' => $v->state?->notation])->values()->all(),
            'names' => $entity->names->sortBy(fn ($n) => [$n->nameType->notation, $n->language, $n->value])->map(fn ($n) => ['id' => $n->id, 'iri' => $n->iri, 'type' => $n->nameType->notation,
                'language' => $n->language, 'value' => $n->value, 'is_original' => (bool) $n->is_original, 'state' => $n->state?->notation])->values()->all(),
            'dates' => $entity->dates->sortBy(fn ($d) => [$d->property?->notation, $d->normalized_value])->map(fn ($d) => ['property' => $d->property?->notation,
                'normalized' => $d->normalized_value, 'expressed' => $d->expressed])->values()->all(),
            'identifiers' => $entity->identifiers->sortBy('value')->map(fn ($i) => ['type' => $i->identifierType?->notation, 'value' => $i->value])->values()->all(),
            'custom_fields' => CustomFieldValue::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->with('definition.fieldType', 'concept')
                ->orderBy('sequence')->get()->sortBy(fn ($v) => [$v->definition->field_key, $v->sequence])->map(fn ($v) => ['key' => $v->definition->field_key,
                    'columns' => $v->only(['value_text', 'value_number', 'value_date', 'value_boolean', 'concept_id']), 'text' => CustomFields::text($v)])->values()->all(),
            'relations' => $relations->sortBy(fn ($r) => [$r->relationType?->notation, $r->id])->map(fn ($r) => ['type' => $r->relationType?->notation,
                'with' => $r->source_id === $entity->getKey() ? "$r->target_table:$r->target_id" : "$r->source_table:$r->source_id",
                'direction' => $r->source_id === $entity->getKey() ? 'from' : 'to'])->values()->all()];
    }

    /**
     * What differs between two versions, field by field: each a list of what was there and what is (Heratio's
     * DiffComputer). Values compare by element and language, names by type and language, the rest as sets.
     *
     * @return array<string, array{before: list<string>, after: list<string>}>
     */
    public function diff(array $old, array $new): array
    {
        $flat = function (array $snap): array {
            $out = [];
            foreach ($snap['base'] ?? [] as $k => $v) {
                $out["base.$k"][] = (string) $v;
            }
            foreach ($snap['values'] ?? [] as $v) {
                $out["value.{$v['element']}.{$v['language']}"][] = (string) $v['value'];
            }
            foreach ($snap['names'] ?? [] as $n) {
                $out["name.{$n['type']}.{$n['language']}"][] = (string) $n['value'];
            }
            foreach ($snap['custom_fields'] ?? [] as $c) {
                $out["custom.{$c['key']}"][] = (string) $c['text'];
            }
            foreach (['dates' => fn ($d) => trim(($d['property'] ?? '').' '.($d['normalized'] ?? '').' '.($d['expressed'] ?? '')),
                'identifiers' => fn ($i) => ($i['type'] ?? '').': '.$i['value'], 'relations' => fn ($r) => $r['type'].' '.$r['direction'].' '.$r['with']] as $part => $show) {
                foreach ($snap[$part] ?? [] as $item) {
                    $out[$part][] = $show($item);
                }
            }

            return array_map(function ($list) {
                sort($list);

                return $list;
            }, $out);
        };
        [$before, $after] = [$flat($old), $flat($new)];
        $diff = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (($before[$field] ?? []) !== ($after[$field] ?? [])) {
                $diff[$field] = ['before' => $before[$field] ?? [], 'after' => $after[$field] ?? []];
            }
        }
        ksort($diff);

        return $diff;
    }

    /** Put back the values and names a version had; the restore is a new version. */
    public function restore(User $actor, Model $entity, int $versionNumber): EntityVersion
    {
        $version = EntityVersion::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(), 'version_number' => $versionNumber])->first()
            ?? throw new InvalidArgumentException(__('There is no version :n.', ['n' => $versionNumber]));
        $kind = $entity instanceof RecordResource ? 'description' : 'authority';
        if ($entity->institution_id !== $actor->institution_id || ! $actor->may('update', $kind, $entity) || ! EntityKinds::readable($actor, $entity)) {
            throw new InvalidArgumentException(__('You may not change it.'));
        }
        $snap = $version->snapshot;
        $draft = Concept::where('iri', Vocab::valueState('draft'))->value('id');
        $elements = Concept::whereIn('iri', array_map(fn ($v) => Vocab::element($v['element']), $snap['values'] ?? []))->pluck('id', 'iri');
        $types = Concept::where('iri', 'like', Vocab::NAME_TYPES.'%')->pluck('id', 'iri');
        self::$paused = true;
        try {
            DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($entity, $snap, $draft, $elements, $types) {
                $where = ['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()];
                $keepValues = array_column($snap['values'] ?? [], 'id');
                DescriptionValue::where($where)->whereNotIn('id', $keepValues)->get()->each->delete();
                foreach ($snap['values'] ?? [] as $v) {
                    $row = DescriptionValue::find($v['id']);
                    $fields = ['element_id' => $elements[Vocab::element($v['element'])] ?? null, 'language' => $v['language'], 'value' => $v['value'], 'is_original' => $v['is_original']];
                    if ($fields['element_id'] === null) {
                        continue;
                    }
                    if ($row === null) {
                        $row = new DescriptionValue([...$where, ...$fields, 'institution_id' => $entity->institution_id, 'state_id' => $draft]);
                        $row->id = $v['id'];
                        $row->save();
                    } elseif ($row->value !== $v['value'] || $row->language !== $v['language']) {
                        $row->update($fields + ['state_id' => $draft]);
                    }
                }
                $keepNames = array_column($snap['names'] ?? [], 'id');
                Name::where($where)->whereNotIn('id', $keepNames)->get()->each(function (Name $n) {
                    $n->dates()->get()->each->delete();
                    $n->delete();
                });
                foreach ($snap['names'] ?? [] as $n) {
                    $row = Name::find($n['id']);
                    $fields = ['name_type_id' => $types[Vocab::NAME_TYPES.$n['type']] ?? null, 'language' => $n['language'], 'value' => $n['value'], 'is_original' => $n['is_original']];
                    if ($fields['name_type_id'] === null) {
                        continue;
                    }
                    if ($row === null) {
                        $row = new Name([...$where, ...$fields, 'institution_id' => $entity->institution_id, 'iri' => $n['iri'], 'state_id' => $draft]);
                        $row->id = $n['id'];
                        $row->save();
                    } elseif ($row->value !== $n['value'] || $row->name_type_id !== $fields['name_type_id'] || $row->language !== $n['language']) {
                        $row->update($fields + ['state_id' => $draft]);
                    }
                }
            }));
            AuditLog::actingAs($actor, fn () => $this->restoreCustomFields($entity, $snap));
        } finally {
            self::$paused = false;
        }
        unset(self::$pending[$entity->getTable().':'.$entity->getKey()]);

        return $this->write($entity->fresh(), $actor->id, __('Restored version :n', ['n' => $versionNumber]), $versionNumber);
    }

    /** The institution's own fields as the version had them (Heratio restored custom_field_value too). */
    private function restoreCustomFields(Model $entity, array $snap): void
    {
        $definitions = CustomFieldDefinition::where(['institution_id' => $entity->institution_id, 'entity_table' => $entity->getTable()])->pluck('id', 'field_key');
        DB::transaction(function () use ($entity, $snap, $definitions) {
            CustomFieldValue::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->get()->each->delete();
            foreach ($snap['custom_fields'] ?? [] as $n => $c) {
                if (isset($definitions[$c['key']])) {
                    CustomFieldValue::create($c['columns'] + ['institution_id' => $entity->institution_id, 'definition_id' => $definitions[$c['key']],
                        'subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(), 'sequence' => $n]);
                }
            }
        });
    }

    /** A snapshot without what changes on every write but says nothing, for telling whether anything changed. */
    private function comparable(array $snap): string
    {
        return json_encode($this->diff([], $snap));
    }
}
