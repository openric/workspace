<?php

// OpenRiC Workspace - activities: browse, create, view and describe (rico:Activity; ADR 0006, WS-003).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Activities are context entities like agents, so the authority permissions
// govern them. Who performed an activity, who took part in it, what it
// resulted in and what documents it are made on its page as relations.

namespace App\Http\Controllers;

use App\Description\ActivityService;
use App\Description\Bcp47;
use App\Description\DateService;
use App\Description\Elements;
use App\Exchange\EacF;
use App\Models\Activity;
use App\Models\Concept;
use App\Models\Name;
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

class ActivityController extends Controller
{
    public function __construct(private ActivityService $activities) {}

    /** Search by name or identifier, filter by activity type. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $query = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $like = '%'.addcslashes($query, '%_\\').'%';
        $activities = $this->existing($user->institution_id)->with('names.nameType', 'activityType.labels', 'identifiers.identifierType', 'dates.property', 'dates.qualifier.labels')
            ->when($query !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->whereIn('id', Name::where('subject_table', 'activities')->where('value', 'ilike', $like)->select('subject_id'))
                ->orWhereIn('id', DB::table('identifiers')->where('subject_table', 'activities')->where('value', 'ilike', $like)->select('subject_id'))))
            ->when($type !== '', fn (Builder $q) => $q->where('activity_type_id', $type))
            ->orderBy(Name::select('value')->whereColumn('subject_id', 'activities.id')->where('subject_table', 'activities')
                ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->orderBy('created_at')->limit(1))
            ->paginate(50)->withQueryString();
        $activities->setCollection($activities->getCollection()->filter(fn (Activity $a) => $user->may('read', 'authority', $a))->values());

        return view('activities.index', ['activities' => $activities, 'query' => $query, 'type' => $type, 'types' => $this->activities->types($user->institution_id)]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->may('create', 'authority'), 403);

        return view('activities.create', ['types' => $this->activities->types($request->user()->institution_id)]);
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
            $activity = $this->activities->create($request->user()->institution, $data['name'], $data['language'], $data['type'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->route('activities.show', $activity)->with('status', __('Activity created. Add who performed it and took part, and what it resulted in.'));
    }

    public function show(Request $request, Activity $activity): View
    {
        $this->own($request, $activity);
        $activity->load('descriptionValues.element.labels', 'descriptionValues.state.labels', 'names.nameType.labels', 'names.state.labels', 'names.dates.property',
            'identifiers.identifierType.labels', 'activityType.labels', 'activityType.vocabulary', 'dates.property.labels', 'dates.dateType.labels', 'dates.qualifier.labels');

        return view('activities.show', ['activity' => $activity, 'dateChoices' => app(DateService::class)->choices('activity'),
            'relations' => app(RelationService::class)->of($activity), 'canUpdate' => $request->user()->may('update', 'authority', $activity)]);
    }

    public function edit(Request $request, Activity $activity): View
    {
        $this->own($request, $activity, 'update');
        $language = (string) $request->query('language', 'en');
        abort_unless(Bcp47::valid($language), 404);
        $activity->load('descriptionValues.element', 'names.nameType', 'activityType');

        return view('activities.edit', ['activity' => $activity, 'language' => $language, 'types' => $this->activities->types($activity->institution_id),
            'statuses' => Concept::choices(Vocab::DESCRIPTION_STATUSES)->with('labels')->get(), 'details' => Concept::choices(Vocab::DESCRIPTION_DETAILS)->with('labels')->get()]);
    }

    public function update(Request $request, Activity $activity): RedirectResponse
    {
        $this->own($request, $activity, 'update');
        $data = $request->validate([
            'language' => ['required', 'string', fn ($a, $v, $fail) => Bcp47::valid((string) $v) || $fail(__('Give a BCP 47 language tag, such as en or af.'))],
            'values' => ['array'],
            'values.*' => ['nullable', 'max:65535'], 'values.*.*' => ['nullable', 'string', 'max:8'],
            'type' => ['nullable', 'uuid'],
            'description_identifier' => ['nullable', 'string', 'max:255'],
            'description_status' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_STATUSES)->pluck('iri'))],
            'description_detail' => ['nullable', 'string', Rule::in(Concept::choices(Vocab::DESCRIPTION_DETAILS)->pluck('iri'))],
        ]);
        $values = array_intersect_key($data['values'] ?? [], array_flip(Elements::notations(Elements::ACTIVITY)));
        try {
            DB::transaction(function () use ($activity, $data, $values, $request) {
                $this->activities->updateType($activity, $data['type'] ?? null, $request->user());
                $this->activities->updateControl($activity, $data['description_identifier'] ?? null, $data['description_status'] ?? null, $data['description_detail'] ?? null, $request->user());
                $this->activities->describe($activity, $values, $data['language'], $request->user());
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['values' => $e->getMessage()]);
        }

        return redirect()->route('activities.show', $activity)->with('status', __('Activity saved.'));
    }

    private function existing(string $institutionId): Builder
    {
        return Activity::where('institution_id', $institutionId)->whereNull('deleted_at');
    }

    /** Another institution's activity, or a deleted one, does not exist here (404); without the permission, 403. */
    /** The activity as EAC-F (roadmap phase 11a). */
    public function eac(Request $request, Activity $activity): Response
    {
        $this->own($request, $activity);
        $name = preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($activity->description_identifier ?? $activity->id));

        return response(app(EacF::class)->export($activity), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="eac-f-'.$name.'.xml"',
        ]);
    }

    private function own(Request $request, Activity $activity, string $action = 'read'): void
    {
        abort_unless($activity->institution_id === $request->user()->institution_id && $activity->deleted_at === null, 404);
        abort_unless($request->user()->may($action, 'authority', $activity), 403);
    }
}
