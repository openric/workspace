<?php

// OpenRiC Workspace - fields an institution adds to its records, authority records, repositories, accessions and
// activities (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-custom-fields (CustomFieldService: definitions, values, validation, export). A field is a row
// with a key, a label, a type, a group and the switches Heratio has: required, searchable, shown to the public, shown
// on the edit form, repeatable, exported. Changed: a dropdown draws from one of the institution's vocabularies, not
// from a separate dropdown manager, so its terms are concepts with IRIs. RiC-O has no term for an institution's own
// field: values are kept, searched and exported in the CSV, and an RDF export lists them as not carried.

namespace App\CustomFields;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Models\Vocabulary;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CustomFields
{
    /** The kinds of entity a field may be added to, by table, with their permission kind. */
    public const KINDS = ['record_resources' => 'description', 'agents' => 'authority', 'repositories' => 'repository', 'accessions' => 'accession', 'activities' => 'authority'];

    /** @return Collection<int, CustomFieldDefinition> */
    public function definitions(string $institutionId, string $table, bool $activeOnly = true): Collection
    {
        return CustomFieldDefinition::where(['institution_id' => $institutionId, 'entity_table' => $table])->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->with('fieldType', 'vocabulary')->orderBy('field_group')->orderBy('sort_order')->orderBy('label')->get();
    }

    public function saveDefinition(string $institutionId, array $data, ?CustomFieldDefinition $definition = null): CustomFieldDefinition
    {
        $table = (string) ($data['entity_table'] ?? $definition?->entity_table);
        isset(self::KINDS[$table]) || throw new InvalidArgumentException(__('Choose what the field is added to.'));
        $label = trim((string) ($data['label'] ?? '')) ?: throw new InvalidArgumentException(__('Give the field a label.'));
        $key = trim((string) ($data['field_key'] ?? '')) ?: trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(Str::ascii($label))), '_');
        preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key) || throw new InvalidArgumentException(__('A key is lower-case letters, digits and underscores, starting with a letter.'));
        if (CustomFieldDefinition::where(['institution_id' => $institutionId, 'entity_table' => $table, 'field_key' => $key])->when($definition, fn ($q) => $q->whereKeyNot($definition->id))->exists()) {
            throw new InvalidArgumentException(__('Another field of these has that key.'));
        }
        $type = Concept::where('iri', Vocab::BASE.'custom-field-type/'.($data['field_type'] ?? ''))->first() ?? throw new InvalidArgumentException(__('Choose the kind of value.'));
        $vocabulary = null;
        if (in_array($type->notation, ['concept', 'concepts'], true)) {
            $vocabulary = Vocabulary::where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institutionId))->find($data['vocabulary_id'] ?? null)
                ?? throw new InvalidArgumentException(__('Choose the vocabulary its terms come from.'));
        }
        $rule = trim((string) ($data['validation_rule'] ?? '')) ?: null;
        if ($rule !== null && @preg_match('~'.str_replace('~', '\~', $rule).'~u', '') === false) {
            throw new InvalidArgumentException(__('The check is not a regular expression the Workspace can use.'));
        }
        $flag = fn (string $k, bool $default) => array_key_exists($k, $data) ? ! empty($data[$k]) : ($definition?->$k ?? $default);
        $fields = ['entity_table' => $table, 'field_key' => $key, 'label' => $label, 'field_type_id' => $type->id, 'vocabulary_id' => $vocabulary?->id,
            'field_group' => trim((string) ($data['field_group'] ?? '')) ?: null, 'is_required' => $flag('is_required', false), 'is_searchable' => $flag('is_searchable', false),
            'is_visible_public' => $flag('is_visible_public', true), 'is_visible_edit' => $flag('is_visible_edit', true), 'is_repeatable' => $flag('is_repeatable', false),
            'include_in_export' => $flag('include_in_export', true), 'default_value' => trim((string) ($data['default_value'] ?? '')) ?: null,
            'help_text' => trim((string) ($data['help_text'] ?? '')) ?: null, 'validation_rule' => $rule, 'sort_order' => (int) ($data['sort_order'] ?? 0), 'is_active' => $flag('is_active', true)];

        return $definition ? tap($definition)->update($fields) : CustomFieldDefinition::create($fields + ['institution_id' => $institutionId]);
    }

    /** @return Collection<string, Collection<int, CustomFieldValue>> the entity's values, by field key */
    public function values(Model $entity): Collection
    {
        return CustomFieldValue::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->with('definition', 'concept')->orderBy('sequence')->get()
            ->groupBy(fn ($v) => $v->definition->field_key);
    }

    /** What a value says, as text. */
    public static function text(CustomFieldValue $value, array $languages = ['en']): string
    {
        return match ($value->definition->fieldType->notation) {
            'boolean' => $value->value_boolean ? __('Yes') : __('No'),
            'number' => rtrim(rtrim(number_format((float) $value->value_number, 6, '.', ''), '0'), '.'),
            'date' => (string) $value->value_date?->format('Y-m-d'),
            'concept', 'concepts' => (string) $value->concept?->label($languages),
            default => (string) $value->value_text,
        };
    }

    /**
     * Save the fields given (by key) for the entity, as Heratio's saveValues: each field given replaces its values; a
     * field not given is left as it is. Required fields, types, the vocabulary and the field's check are enforced.
     *
     * @param  array<string, mixed>  $input  field key => a value, or a list for a repeatable field
     */
    public function save(User $actor, Model $entity, array $input): void
    {
        $table = $entity->getTable();
        isset(self::KINDS[$table]) || throw new InvalidArgumentException(__('Fields cannot be added to this.'));
        if ($entity->getAttribute('institution_id') !== $actor->institution_id || ! $actor->may('update', self::KINDS[$table], $entity)) {
            throw new InvalidArgumentException(__('You may not change it.'));
        }
        $rows = [];
        foreach ($this->definitions($actor->institution_id, $table) as $definition) {
            if (! array_key_exists($definition->field_key, $input)) {
                continue;
            }
            $given = $input[$definition->field_key];
            $list = array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, is_array($given) ? $given : [$given]),
                fn ($v) => $v !== null && $v !== '' && $v !== false));
            $type = $definition->fieldType->notation;
            if ($type === 'boolean') {
                $list = [! empty($given) && $given !== '0'];
            }
            if ($list === [] && $definition->is_required) {
                throw new InvalidArgumentException(__(':field is required.', ['field' => $definition->label]));
            }
            if (count($list) > 1 && ! $definition->is_repeatable && $type !== 'concepts') {
                throw new InvalidArgumentException(__(':field takes one value.', ['field' => $definition->label]));
            }
            $rows[$definition->id] = array_map(fn ($v) => $this->column($definition, $v), $list);
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($entity, $rows) {
            foreach ($rows as $definitionId => $values) {
                CustomFieldValue::where(['definition_id' => $definitionId, 'subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->get()->each->delete();
                foreach ($values as $n => $columns) {
                    CustomFieldValue::create($columns + ['institution_id' => $entity->getAttribute('institution_id'), 'definition_id' => $definitionId,
                        'subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(), 'sequence' => $n]);
                }
            }
        }));
    }

    /** The columns one value is kept in, checked against its type and the field's check. */
    private function column(CustomFieldDefinition $definition, mixed $value): array
    {
        $bad = fn () => throw new InvalidArgumentException(__(':value is not a valid value for :field.', ['value' => is_scalar($value) ? $value : '', 'field' => $definition->label]));
        if ($definition->validation_rule && is_string($value) && ! preg_match('~'.str_replace('~', '\~', $definition->validation_rule).'~u', $value)) {
            $bad();
        }

        return match ($definition->fieldType->notation) {
            'number' => is_numeric($value) ? ['value_number' => (float) $value] : $bad(),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? ['value_date' => $value] : $bad(),
            'boolean' => ['value_boolean' => (bool) $value],
            'url' => filter_var($value, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $value) ? ['value_text' => $value] : $bad(),
            'concept', 'concepts' => ($id = Concept::where('vocabulary_id', $definition->vocabulary_id)->whereNull('deprecated_at')
                ->where(fn ($q) => $q->where('id', is_string($value) && preg_match('/^[0-9a-f-]{36}$/', $value) ? $value : null)->orWhere('notation', (string) $value))->value('id')) ? ['concept_id' => $id] : $bad(),
            default => ['value_text' => mb_substr((string) $value, 0, 20000)],
        };
    }

    /** The text the search index takes from an entity's searchable fields: the public entry only what the public sees. @return list<string> */
    public static function searchable(Model $entity, bool $public): array
    {
        if (! isset(self::KINDS[$entity->getTable()])) {
            return [];
        }

        return CustomFieldValue::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey()])->with('definition.fieldType', 'concept.labels')->get()
            ->filter(fn ($v) => $v->definition->is_active && $v->definition->is_searchable && (! $public || $v->definition->is_visible_public))
            ->map(fn ($v) => self::text($v, ['en', 'und']))->filter()->values()->all();
    }
}
