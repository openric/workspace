<?php

// OpenRiC Workspace - rules and mandates: browse, create, view and describe (rico:Rule, rico:Mandate; roadmap phase 23b).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After App\Http\Controllers\ActivityController: rules are context entities like activities, so the authority
// permissions govern them. What a mandate gives authority to and what a rule regulates are made on its page as relations.

namespace App\Http\Controllers;

use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\Elements;
use App\Description\RuleService;
use App\Models\Concept;
use App\Models\Name;
use App\Models\Rule;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\View\View;
use InvalidArgumentException;

class RuleController extends Controller
{
    public function __construct(private RuleService $rules) {}

    /** Search by name or identifier, filter by rule type. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $query = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $like = '%'.addcslashes($query, '%_\\').'%';
        $rules = $this->existing($user->institution_id)->with('names.nameType', 'ruleType.labels', 'identifiers.identifierType', 'dates.property', 'dates.qualifier.labels')
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'rules')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', DB::table('identifiers')->where('subject_table', 'rules')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($type !== '', fn (Builder $q) => $q->where('rule_type_id', $type))
            ->orderBy(Name::select('value')->whereColumn('subject_id', 'rules.id')->where('subject_table', 'rules')
                ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->orderBy('created_at')->limit(1))
            ->paginate(50)->withQueryString();
        $rules->setCollection($rules->getCollection()->filter(fn (Rule $a) => $user->may('read', 'authority', $a))->values());

        return view('rules.index', ['rules' => $rules, 'query' => $query, 'type' => $type, 'types' => $this->rules->types($user->institution_id)]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'authority'), 403);

        return view('rules.create', ['types' => $this->rules->types($request->user()->institution_id)]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->may('create', 'authority'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:2000'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'type' => ['nullable', 'uuid'],
        ]);
        try {
            $rule = $this->rules->create($request->user()->institution, $data['name'], $data['language'], $data['type'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->route('rules.show', $rule)->with('status', __('Rule created. Add whom it gives authority to and what it regulates.'));
    }

    public function show(Request $request, Rule $rule): View
    {
        $this->own($request, $rule);
        $rule->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property',
            'identifiers.identifierType.labels', 'ruleType.labels', 'ruleType.vocabulary', 'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels');

        return view('rules.show', ['rule' => $rule, 'dateChoices' => app(DateService::class)->choices('rule'),
            'relations' => app(RelationService::class)->of($rule), 'canUpdate' => $request->user()->may('update', 'authority', $rule)]);
    }

    public function edit(Request $request, Rule $rule): View
    {
        $this->own($request, $rule, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $rule->load('descriptionValues.element', 'names.nameType', 'ruleType');

        return view('rules.edit', ['rule' => $rule, 'language' => $language, 'types' => $this->rules->types($rule->institution_id),
            'statuses' => Concept::choices(Vocab::DESCRIPTION_STATUSES)->with('labels')->get(), 'details' => Concept::choices(Vocab::DESCRIPTION_DETAILS)->with('labels')->get()]);
    }

    public function update(Request $request, Rule $rule): RedirectResponse
    {
        $this->own($request, $rule, 'update');
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'values' => ['array'],
            'values.*' => ['nullable', 'max:65535'], 'values.*.*' => ['nullable', 'string', 'max:8'],
            'type' => ['nullable', 'uuid'],
            'authority_iri' => ['nullable', 'string', 'max:2048'],
            'description_identifier' => ['nullable', 'string', 'max:255'],
            'description_status' => ['nullable', 'string', ValidationRule::in(Concept::choices(Vocab::DESCRIPTION_STATUSES)->pluck('iri'))],
            'description_detail' => ['nullable', 'string', ValidationRule::in(Concept::choices(Vocab::DESCRIPTION_DETAILS)->pluck('iri'))],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::RULE)));
        try {
            DB::transaction(function () use ($rule, $data, $values, $request) {
                $this->rules->updateType($rule, $data['type'] ?? null, $request->user());
                $this->rules->updateAuthority($rule, $data['authority_iri'] ?? null, $request->user());
                $this->rules->updateControl($rule, $data['description_identifier'] ?? null, $data['description_status'] ?? null, $data['description_detail'] ?? null, $request->user());
                $this->rules->describe($rule, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('rules.show', $rule)->with('status', __('Rule saved.'));
    }

    private function existing(string $institutionId): Builder
    {
        return Rule::where('institution_id', $institutionId)->whereNull('deleted_at');
    }

    /** Another institution's rule, or a deleted one, does not exist here (404); without the permission, 403. */
    private function own(Request $request, Rule $rule, string $action = 'read'): void
    {
        abort_unless($rule->institution_id === $request->user()->institution_id && $rule->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'authority', $rule), 403);
    }
}
