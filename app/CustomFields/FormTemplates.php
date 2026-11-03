<?php

// OpenRiC Workspace - form templates: the fields a form shows for one kind of entity, where each template applies, and
// describing an entity through one (roadmap phase 22b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-forms (FormService: templates, fields, assignments, resolveTemplate; TemplateEditController).
// A template lists the description elements of its kind (ISAD(G) with the elements only DACS, RAD or MODS have,
// ISAAR(CPF), ISDIAH) and the institution's own fields, each with its label, help, default, whether it is required,
// and its section, in order. It applies where an assignment says, by repository, level of description or collection
// (a collection's assignment covering what is inside it unless it says otherwise), the lowest priority number first
// as in Heratio, then the kind's default. Describing an entity through a template writes through the same services as
// the full form, so a value written is a draft and its RiC term is the element's. Changed: Heratio's fields could map
// to any table column; here a field is an element or an institution's own field, nothing else.

namespace App\CustomFields;

use App\Audit\AuditLog;
use App\Description\AgentService;
use App\Description\Elements;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Models\Concept;
use App\Models\FormAssignment;
use App\Models\FormTemplate;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FormTemplates
{
    /** The kinds a template is made for, with their standards and permission kind. */
    public const KINDS = ['record_resources' => [[Elements::ISAD_G, Elements::OTHER_STANDARDS], 'description'], 'agents' => [[Elements::ISAAR_CPF], 'authority'],
        'repositories' => [[Elements::ISDIAH], 'repository']];

    public function __construct(private CustomFields $customFields) {}

    /** @return Collection<string, Concept> the elements a template of the kind may show, by notation */
    public function elements(string $table): Collection
    {
        return collect(self::KINDS[$table][0])->flatMap(fn ($s) => Elements::of($s))->keyBy('notation');
    }

    /** Save a template with its fields (in the order given) and assignments. */
    public function save(User $actor, array $data, ?FormTemplate $template = null): FormTemplate
    {
        $table = (string) ($data['entity_table'] ?? $template?->entity_table);
        isset(self::KINDS[$table]) || throw new InvalidArgumentException(__('Choose what the form describes.'));
        $name = trim((string) ($data['name'] ?? '')) ?: throw new InvalidArgumentException(__('Name the template.'));
        $elements = $this->elements($table);
        $custom = $this->customFields->definitions($actor->institution_id, $table)->keyBy('field_key');
        $fields = [];
        foreach (array_values($data['fields'] ?? []) as $n => $f) {
            $target = trim((string) ($f['target'] ?? ''));
            if ($target === '') {
                continue;
            }
            $known = str_starts_with($target, 'custom:') ? $custom->has(substr($target, 7)) : $elements->has($target);
            $known || throw new InvalidArgumentException(__(':field is not a field of these.', ['field' => $target]));
            $fields[$target] = ['target' => $target, 'label' => trim((string) ($f['label'] ?? '')) ?: null, 'help_text' => trim((string) ($f['help_text'] ?? '')) ?: null,
                'default_value' => trim((string) ($f['default_value'] ?? '')) ?: null, 'is_required' => ! empty($f['is_required']),
                'section_name' => trim((string) ($f['section_name'] ?? '')) ?: null, 'sort_order' => $n];
        }
        $fields !== [] || throw new InvalidArgumentException(__('A template shows at least one field.'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $data, $template, $table, $name, $fields) {
            $attributes = ['name' => $name, 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'entity_table' => $table,
                'is_default' => ! empty($data['is_default']), 'is_active' => ! array_key_exists('is_active', $data) || ! empty($data['is_active'])];
            if ($attributes['is_default']) {
                FormTemplate::where(['institution_id' => $actor->institution_id, 'entity_table' => $table])->when($template, fn ($q) => $q->whereKeyNot($template->id))->update(['is_default' => false]);
            }
            $template = $template ? tap($template)->update($attributes + ['version' => $template->version + 1])
                : FormTemplate::create($attributes + ['institution_id' => $actor->institution_id, 'created_by_id' => $actor->id]);
            $template->fields()->get()->each->delete();
            foreach ($fields as $field) {
                $template->fields()->create($field);
            }
            $template->assignments()->get()->each->delete();
            foreach ($data['assignments'] ?? [] as $a) {
                if (! array_filter([$a['repository_id'] ?? null, $a['level'] ?? null, $a['collection_iri'] ?? null])) {
                    continue;
                }
                $collection = ($a['collection_iri'] ?? '') ? RecordResource::where(['institution_id' => $actor->institution_id, 'iri' => trim($a['collection_iri'])])->first()
                    ?? throw new InvalidArgumentException(__('No record has that IRI.')) : null;
                $template->assignments()->create(['repository_id' => ($a['repository_id'] ?? '') ?: null,
                    'level_id' => ($a['level'] ?? '') ? Concept::where('iri', Vocab::BASE.'level-of-description/'.$a['level'])->value('id') : null,
                    'collection_id' => $collection?->id, 'priority' => (int) ($a['priority'] ?? 100), 'inherit_to_children' => ! array_key_exists('inherit_to_children', $a) || ! empty($a['inherit_to_children'])]);
            }

            return $template->fresh('fields', 'assignments');
        }));
    }

    /** The template that applies to the entity (Heratio's resolveTemplate), or null. */
    public function resolve(Model $entity): ?FormTemplate
    {
        $table = $entity->getTable();
        $templates = FormTemplate::where(['institution_id' => $entity->getAttribute('institution_id'), 'entity_table' => $table, 'is_active' => true])->pluck('id');
        if ($templates->isEmpty()) {
            return null;
        }
        $ancestors = $entity instanceof RecordResource ? collect($entity->ancestors()) : collect();
        $repositoryId = $entity instanceof RecordResource ? ($entity->repository_id ?? $ancestors->first(fn ($a) => $a->repository_id)?->repository_id) : $entity->getKey();
        foreach (FormAssignment::whereIn('template_id', $templates)->where('is_active', true)->orderBy('priority')->get() as $a) {
            $matches = (! $a->repository_id || $a->repository_id === $repositoryId)
                && (! $a->level_id || ($entity instanceof RecordResource && $a->level_id === $entity->level_id))
                && (! $a->collection_id || ($entity instanceof RecordResource && ($a->collection_id === $entity->id || ($a->inherit_to_children && $ancestors->contains('id', $a->collection_id)))));
            if ($matches) {
                return FormTemplate::with('fields')->find($a->template_id);
            }
        }

        return FormTemplate::with('fields')->whereIn('id', $templates)->where('is_default', true)->first();
    }

    /**
     * Describe the entity through the template, in one language: elements through the kind's own service, its own
     * fields through CustomFields. A required field left empty is refused, naming it.
     *
     * @param  array<string, mixed>  $input  target => value
     */
    public function describe(User $actor, FormTemplate $template, Model $entity, array $input, string $language): void
    {
        $template->entity_table === $entity->getTable() || throw new InvalidArgumentException(__('The template is for another kind of entity.'));
        if (! $actor->may('update', self::KINDS[$template->entity_table][1], $entity)) {
            throw new InvalidArgumentException(__('You may not change it.'));
        }
        $values = [];
        $custom = [];
        foreach ($template->fields as $field) {
            $given = $input[str_replace(':', '__', $field->target)] ?? null;
            $empty = $given === null || $given === '' || $given === [];
            if ($empty && $field->is_required) {
                throw new InvalidArgumentException(__(':field is required.', ['field' => $field->label ?? $field->target]));
            }
            str_starts_with($field->target, 'custom:') ? $custom[substr($field->target, 7)] = $given : $values[$field->target] = is_array($given) ? implode("\n", $given) : (string) $given;
        }
        DB::transaction(function () use ($actor, $entity, $values, $custom, $language) {
            if ($values !== []) {
                match ($entity->getTable()) {
                    'record_resources' => app(RecordService::class)->describe($entity, $values, $language, $actor),
                    'agents' => app(AgentService::class)->describe($entity, $values, $language, $actor),
                    'repositories' => app(RepositoryService::class)->describe($entity, $values, $language, $actor),
                };
            }
            if ($custom !== []) {
                $this->customFields->save($actor, $entity, $custom);
            }
        });
    }
}
