{{-- OpenRiC Workspace - one activity, with who performed it, who took part, what it resulted in and what documents it. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $activity->described('activity-name', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('activities.index') }}">{{ __('Activities') }}</a></p>
    <h1>{{ $activity->described('activity-name', [app()->getLocale(), 'en']) }}</h1>
    <p>Activity @include('description._ric', ['term' => 'rico:Activity'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $activity->iri }}</code>
        <br><strong>{{ __('Activity type') }}:</strong> {{ $activity->activityType ? $activity->activityType->anyLabel(['en']).' ('.$activity->activityType->vocabulary->title.')' : 'not set' }} @include('description._ric', ['term' => 'rico:hasActivityType'])</p>
    @if($canUpdate)
        @include('description._actions', ['subject' => $activity, 'editRoute' => 'activities.edit', 'required' => 'activity-name'])
    @endif
    @include('description._view', ['subject' => $activity, 'standard' => \App\Description\Elements::ACTIVITY, 'drafts' => $canUpdate])

    @include('description._dates', ['subject' => $activity, 'kind' => 'activities', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])
    @include('description._relations', ['subject' => $activity, 'kind' => 'activities', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Performed by', 'types' => ['performance'], 'section' => 'pf', 'add' => 'performance|source', 'addLabel' => 'Add who performed it, in a role'])
    @include('description._relations', ['subject' => $activity, 'kind' => 'activities', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Participants', 'types' => ['participation'], 'section' => 'pa', 'add' => 'participation|source', 'addLabel' => 'Add a participant, in a role such as spouse or witness'])
    @include('description._relations', ['subject' => $activity, 'kind' => 'activities', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Resulted in', 'types' => ['result'], 'section' => 're', 'add' => 'result|source', 'addLabel' => 'Add a unit of description it resulted in'])
    @include('description._relations', ['subject' => $activity, 'kind' => 'activities', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Documented by', 'types' => ['documentation'], 'section' => 'do', 'add' => 'documentation|target', 'addLabel' => 'Add a unit of description that documents it'])
    @include('description._relations', ['subject' => $activity, 'kind' => 'activities', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Part of, and parts', 'types' => ['subevent'], 'section' => 'sb', 'add' => 'subevent|source', 'addLabel' => 'Add an activity that is part of it'])
    @include('description._relations', ['subject' => $activity, 'kind' => 'activities', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Places', 'types' => ['place'], 'section' => 'pl', 'add' => 'place|target', 'addLabel' => 'Add a place where it took place'])
    @include('description._relations', ['subject' => $activity, 'kind' => 'activities', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Units of description about it', 'types' => ['subject'], 'section' => 'su', 'add' => 'subject|target', 'addLabel' => 'Add a unit of description about it'])
    @include('description._names', ['subject' => $activity, 'kind' => 'activities', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $activity, 'kind' => 'activities', 'canUpdate' => $canUpdate])
@endsection
