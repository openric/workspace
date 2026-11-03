<?php

// OpenRiC Workspace - the institution's own fields and form templates: defining them, and describing an entity with
// its own fields or through a template (roadmap phase 22b), after Heratio's ahg-custom-fields admin and edit partial
// and ahg-forms (FormsController, TemplateEditController).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\CustomFields\CustomFields;
use App\CustomFields\FormTemplates;
use App\Models\Accession;
use App\Models\Activity;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\CustomFieldDefinition;
use App\Models\FormTemplate;
use App\Models\RecordResource;
use App\Models\Repository;
use App\Models\Vocabulary;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class CustomFieldController extends Controller
{
    /** Route kinds, their tables and models. */
    public const KINDS = ['records' => ['record_resources', RecordResource::class], 'agents' => ['agents', Agent::class],
        'repositories' => ['repositories', Repository::class], 'accessions' => ['accessions', Accession::class], 'activities' => ['activities', Activity::class]];

    public function __construct(private CustomFields $fields, private FormTemplates $forms) {}

    // Defining fields and templates (who administers the institution).

    public function index(Request $request): View
    {
        $institution = $request->user()->institution_id;

        return view('custom-fields.index', ['definitions' => CustomFieldDefinition::where('institution_id', $institution)->with('fieldType', 'vocabulary')
            ->orderBy('entity_table')->orderBy('field_group')->orderBy('sort_order')->get()->groupBy('entity_table'),
            'types' => Concept::choices(Vocab::BASE.'custom-field-type/')->get(),
            'vocabularies' => Vocabulary::where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institution))->orderBy('title')->get(),
            'templates' => FormTemplate::where('institution_id', $institution)->withCount('fields', 'assignments')->orderBy('entity_table')->orderBy('name')->get()]);
    }

    public function saveDefinition(Request $request, ?CustomFieldDefinition $definition = null): RedirectResponse
    {
        abort_if($definition && $definition->institution_id !== $request->user()->institution_id, 404);
        try {
            $this->fields->saveDefinition($request->user()->institution_id, $request->all(), $definition);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['fields' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function template(Request $request, ?FormTemplate $template = null): View
    {
        abort_if($template && $template->institution_id !== $request->user()->institution_id, 404);
        $table = $template?->entity_table ?? (string) $request->query('for', 'record_resources');
        abort_unless(isset(FormTemplates::KINDS[$table]), 404);

        return view('custom-fields.template', ['template' => $template?->load('fields', 'assignments.collection'), 'table' => $table, 'elements' => $this->forms->elements($table),
            'custom' => $this->fields->definitions($request->user()->institution_id, $table),
            'repositories' => Repository::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->get(),
            'levels' => Concept::choices(Vocab::BASE.'level-of-description/')->get()]);
    }

    public function saveTemplate(Request $request, ?FormTemplate $template = null): RedirectResponse
    {
        abort_if($template && $template->institution_id !== $request->user()->institution_id, 404);
        try {
            $template = $this->forms->save($request->user(), $request->all(), $template);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['forms' => $e->getMessage()]);
        }

        return redirect()->route('custom-fields.template', $template)->with('status', __('Saved.'));
    }

    // Describing an entity with its own fields, or through a template.

    public function entity(Request $request, string $kind, string $id): View
    {
        $entity = $this->entityOf($request, $kind, $id);
        $template = null;
        if (isset(FormTemplates::KINDS[$entity->getTable()])) {
            $template = $request->query('template')
                ? FormTemplate::where(['institution_id' => $entity->institution_id, 'entity_table' => $entity->getTable(), 'is_active' => true])->with('fields')->find($request->query('template'))
                : $this->forms->resolve($entity);
        }
        $language = (string) $request->query('language', 'en');

        return view('custom-fields.entity', ['entity' => $entity, 'kind' => $kind, 'template' => $template, 'language' => $language,
            'templates' => FormTemplate::where(['institution_id' => $entity->institution_id, 'entity_table' => $entity->getTable(), 'is_active' => true])->orderBy('name')->get(),
            'definitions' => $this->fields->definitions($entity->institution_id, $entity->getTable())->where('is_visible_edit', true),
            'values' => $this->fields->values($entity), 'elements' => isset(FormTemplates::KINDS[$entity->getTable()]) ? $this->forms->elements($entity->getTable()) : collect()]);
    }

    public function saveEntity(Request $request, string $kind, string $id): RedirectResponse
    {
        $entity = $this->entityOf($request, $kind, $id);
        try {
            if ($request->filled('template')) {
                $template = FormTemplate::where(['institution_id' => $entity->institution_id, 'entity_table' => $entity->getTable()])->with('fields')->findOrFail($request->input('template'));
                $this->forms->describe($request->user(), $template, $entity, (array) $request->input('form', []), (string) $request->input('language', 'en'));
            } else {
                $this->fields->save($request->user(), $entity, (array) $request->input('custom', []));
            }
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['fields' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    private function entityOf(Request $request, string $kind, string $id): Model
    {
        [$table, $class] = self::KINDS[$kind] ?? abort(404);
        $entity = $class::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->find($id) ?? abort(404);
        abort_unless($request->user()->may('update', CustomFields::KINDS[$table], $entity) && EntityKinds::readable($request->user(), $entity), 404);

        return $entity;
    }
}
