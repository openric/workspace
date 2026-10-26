<?php

// OpenRiC Workspace - authority records: browse, create, view, describe, contacts, merge, split, duplicates, dashboard, EAC-CPF and print (ISAAR(CPF)).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Every route needs read on authority records; changes need create, update or
// delete (ADR 0005). An embargoed record exists only for those who may update
// it. A merged record's page leads to the record it was merged into.

namespace App\Http\Controllers;

use App\Description\AgentService;
use App\Description\Bcp47;
use App\Description\ContactPointService;
use App\Description\DateService;
use App\Description\EacCpf;
use App\Description\Elements;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Name;
use App\Models\Relation;
use App\Models\Repository;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class AgentController extends Controller
{
    private const WITH = ['names.nameType', 'agentType.labels', 'identifiers.identifierType', 'descriptionValues.element', 'descriptionStatus.labels', 'dates.property'];

    public function __construct(private AgentService $agents, private ContactPointService $contacts) {}

    /** Search names and identifiers; filter by type and status; sort by name or last change. */
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');
        $sort = $request->query('sort') === 'updated' ? 'updated' : 'name';
        $like = '%'.addcslashes($query, '%_\\').'%';
        $agents = $this->visible($request)->with(self::WITH)
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'agents')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', DB::table('identifiers')->where('subject_table', 'agents')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($type !== '', fn (Builder $q) => $q->whereHas('agentType', fn ($q) => $q->where('notation', $type)))
            ->when($status !== '', fn (Builder $q) => $q->where('description_status_id', $status))
            ->when($sort === 'updated', fn (Builder $q) => $q->orderByDesc('updated_at'),
                fn (Builder $q) => $q->orderBy(Name::select('value')->whereColumn('subject_id', 'agents.id')->where('subject_table', 'agents')
                    ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->orderBy('created_at')->limit(1)))
            ->paginate(50)->withQueryString();

        return view('agents.index', ['agents' => $agents, 'query' => $query, 'type' => $type, 'status' => $status, 'sort' => $sort,
            'types' => Concept::choices(Agent::TYPES)->get(), 'statuses' => Concept::choices(Vocab::DESCRIPTION_STATUSES)->get()]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'authority'), 403);

        return view('agents.create', ['types' => Concept::choices(Agent::TYPES)->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->may('create', 'authority'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(Concept::choices(Agent::TYPES)->pluck('notation'))],
            'name' => ['required', 'string', 'max:1024'],
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'identifier' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $agent = $this->agents->create($request->user()->institution, $data['type'], $data['name'], $data['language'], $data['identifier'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('agents.edit', [$agent, 'language' => $data['language']])->with('status', __('Authority record created. Describe it now, or later.'));
    }

    public function show(Request $request, Agent $agent): View|RedirectResponse
    {
        if ($merged = $this->merged($request, $agent)) {
            return $merged;
        }
        $this->own($request, $agent);

        return view('agents.show', $this->page($agent) + ['print' => false]);
    }

    /** The authority record on one page, without its forms. */
    public function print(Request $request, Agent $agent): View
    {
        $this->own($request, $agent);

        return view('agents.show', $this->page($agent) + ['print' => true]);
    }

    public function edit(Request $request, Agent $agent): View
    {
        $this->own($request, $agent, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $agent->load('descriptionValues.element', 'names.nameType', 'identifiers.identifierType', 'agentType');

        return view('agents.edit', ['agent' => $agent, 'language' => $language, 'types' => Concept::choices(Agent::TYPES)->get(),
            'statuses' => Concept::choices(Vocab::DESCRIPTION_STATUSES)->get(), 'details' => Concept::choices(Vocab::DESCRIPTION_DETAILS)->get(),
            'repositories' => Repository::where('institution_id', $agent->institution_id)->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get()]);
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $this->own($request, $agent, 'update');
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'type' => ['required', 'string', 'max:64'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'values' => ['array'],
            'values.*' => ['nullable', 'string', 'max:65535'],
            'description_identifier' => ['nullable', 'string', 'max:255'],
            'maintainer' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_STATUSES)->pluck('iri'))],
            'detail' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_DETAILS)->pluck('iri'))],
            'embargo_until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::ISAAR_CPF)));
        try {
            DB::transaction(function () use ($agent, $data, $values, $request) {
                $this->agents->updateControl($agent, $data, $request->user());
                $this->agents->describe($agent, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('agents.show', $agent)->with('status', __('Authority record saved.'));
    }

    public function storeContact(Request $request, Agent $agent): RedirectResponse
    {
        $this->own($request, $agent, 'update');
        $this->contacts->save($agent, $this->contactFields($request), null, $request->user());

        return redirect()->route('agents.show', $agent)->with('status', __('Contact point added.'));
    }

    public function updateContact(Request $request, Agent $agent, ContactPoint $contact): RedirectResponse
    {
        $this->ownContact($request, $agent, $contact);
        $this->contacts->save($agent, $this->contactFields($request), $contact, $request->user());

        return redirect()->route('agents.show', $agent)->with('status', __('Contact point saved.'));
    }

    public function destroyContact(Request $request, Agent $agent, ContactPoint $contact): RedirectResponse
    {
        $this->ownContact($request, $agent, $contact);
        $this->contacts->remove($contact, $request->user());

        return redirect()->route('agents.show', $agent)->with('status', __('Contact point removed.'));
    }

    /** Find the agent to merge this one into. */
    public function mergeForm(Request $request, Agent $agent): View
    {
        $this->own($request, $agent, 'delete');
        $query = trim((string) $request->query('q', ''));
        $candidates = $query === '' ? collect() : app(RelationService::class)->candidates($request->user(), ['agent'], $query, $agent);

        return view('agents.merge', ['agent' => $agent->load(self::WITH), 'query' => $query, 'candidates' => $candidates]);
    }

    public function merge(Request $request, Agent $agent): RedirectResponse
    {
        $this->own($request, $agent, 'delete');
        $kept = $this->find($request, (string) $request->validate(['into' => ['required', 'uuid']])['into']);
        abort_unless($request->user()->may('update', 'authority', $kept), 403);
        try {
            $this->agents->merge($agent, $kept, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['into' => $e->getMessage()]);
        }

        return redirect()->route('agents.show', $kept)->with('status', __('Merged: the duplicate\'s names, identifiers, relations and contact points are here now, and its IRI leads here.'));
    }

    public function splitForm(Request $request, Agent $agent): View
    {
        $this->own($request, $agent, 'update');
        abort_unless($request->user()->may('create', 'authority'), 403);

        return view('agents.split', ['agent' => $agent->load(self::WITH), 'relations' => app(RelationService::class)->of($agent),
            'types' => Concept::choices(Agent::TYPES)->get()]);
    }

    public function split(Request $request, Agent $agent): RedirectResponse
    {
        $this->own($request, $agent, 'update');
        abort_unless($request->user()->may('create', 'authority'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(Concept::choices(Agent::TYPES)->pluck('notation'))],
            'names' => ['required', 'array'], 'names.*' => ['uuid'],
            'relations' => ['array'], 'relations.*' => ['uuid'],
        ]);
        $mine = fn ($q) => $q->where(fn ($q) => $q->where(['source_table' => 'agents', 'source_id' => $agent->id])->orWhere(fn ($q) => $q->where(['target_table' => 'agents', 'target_id' => $agent->id])));
        $relationIds = Relation::whereKey($data['relations'] ?? [])->where($mine)->pluck('id')->all();
        try {
            $new = $this->agents->split($agent, $data['type'], $data['names'], $relationIds, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['names' => $e->getMessage()]);
        }

        return redirect()->route('agents.show', $new)->with('status', __('Split: this new authority record has the names and relations chosen.'));
    }

    /** Pairs that may be the same agent, to compare, merge or dismiss. */
    public function duplicates(Request $request): View
    {
        abort_unless($request->user()->may('update', 'authority'), 403);
        $pairs = $this->agents->duplicates($request->user()->institution_id)
            ->filter(fn ($pair) => $request->user()->may('update', 'authority', $pair[0]) && $request->user()->may('update', 'authority', $pair[1]))->values();

        return view('agents.duplicates', ['pairs' => $pairs]);
    }

    public function dismiss(Request $request, Agent $agent): RedirectResponse
    {
        $this->own($request, $agent, 'update');
        $other = $this->find($request, (string) $request->validate(['other' => ['required', 'uuid']])['other']);
        $this->agents->dismiss($agent, $other, $request->user());

        return redirect()->route('agents.duplicates')->with('status', __('Marked as not duplicates.'));
    }

    /** Counts by type and status, and the records that lack an essential element, as a work queue. */
    public function dashboard(Request $request): View
    {
        $agents = $this->visible($request)->with(self::WITH)->get();
        $completeness = $agents->mapWithKeys(fn (Agent $a) => [$a->id => $this->agents->completeness($a)]);

        return view('agents.dashboard', ['agents' => $agents, 'completeness' => $completeness,
            'byType' => $agents->groupBy(fn (Agent $a) => $a->agentType->label(['en', 'und']))->map->count()->sortKeys(),
            'byStatus' => $agents->groupBy(fn (Agent $a) => $a->descriptionStatus?->label(['en', 'und']) ?? 'Not set')->map->count()->sortKeys(),
            'queue' => $agents->filter(fn (Agent $a) => $completeness[$a->id]['missing'] !== [])->sortBy(fn (Agent $a) => $completeness[$a->id]['percent'])->values(),
            'recent' => $agents->sortByDesc('updated_at')->take(10)->values()]);
    }

    public function eac(Request $request, Agent $agent): Response
    {
        $this->own($request, $agent);
        abort_unless(EacCpf::exportable($agent), 404);
        $name = preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($agent->description_identifier ?? $agent->id));

        return response(app(EacCpf::class)->export($agent), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="eac-cpf-'.$name.'.xml"',
        ]);
    }

    private function page(Agent $agent): array
    {
        $agent->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property',
            'identifiers.identifierType.labels', 'contactPoints', 'descriptionStatus.labels', 'descriptionDetail.labels', 'agentType.labels',
            'maintainer.names.nameType', 'maintainer.descriptionValues.element', 'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels');
        $dateKind = $agent->typeNotation() === 'person' ? 'agent:person' : 'agent';

        return ['agent' => $agent, 'dateChoices' => app(DateService::class)->choices($dateKind), 'relations' => app(RelationService::class)->of($agent),
            'completeness' => $this->agents->completeness($agent)];
    }

    /** The agents the account may see: not deleted, readable, and not embargoed unless it may update them. */
    private function visible(Request $request): Builder
    {
        $user = $request->user();
        $query = Agent::where('institution_id', $user->institution_id)->whereNull('deleted_at');
        if (! $user->mayAnywhere('update', 'authority')) {
            $query->where(fn ($q) => $q->whereNull('embargo_until')->orWhere('embargo_until', '<=', now()->toDateString()));
        }

        return $query;
    }

    /** A merged record leads to the record it was merged into. */
    private function merged(Request $request, Agent $agent): ?RedirectResponse
    {
        if ($agent->merged_into_id && $agent->institution_id === $request->user()->institution_id) {
            return redirect()->route('agents.show', $agent->merged_into_id)->with('status', __('That authority record was merged into this one.'));
        }

        return null;
    }

    private function find(Request $request, string $id): Agent
    {
        return Agent::where(['id' => $id, 'institution_id' => $request->user()->institution_id])->whereNull('deleted_at')->first() ?? abort(404);
    }

    private function contactFields(Request $request): array
    {
        return $this->contacts->fieldsFrom($request);
    }

    private function ownContact(Request $request, Agent $agent, ContactPoint $contact): void
    {
        $this->own($request, $agent, 'update');
        abort_unless($contact->subject_table === $agent->getTable() && $contact->subject_id === $agent->id, 404);
    }

    /** Another institution's record, a deleted one, or an embargoed one the account may not update, does not exist here (404); without the permission, 403. */
    private function own(Request $request, Agent $agent, string $action = 'read'): void
    {
        $user = $request->user();
        abort_unless($agent->institution_id === $user->institution_id && $agent->deleted_at === null, 404);
        abort_if($agent->isEmbargoed() && ! $user->may('update', 'authority', $agent), 404);
        abort_unless($user->may($action, 'authority', $agent), 403);
    }
}
