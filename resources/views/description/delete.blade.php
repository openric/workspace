{{-- OpenRiC Workspace - confirm deleting an entity. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = \App\Relations\EntityKinds::label($entity))
@section('title', __('Delete :name', ['name' => $name]))
@section('content')
    <p><a href="{{ \App\Relations\EntityKinds::route($entity) }}">{{ __('Back to :name', ['name' => $name]) }}</a></p>
    <h1>{{ __('Delete :name?', ['name' => $name]) }}</h1>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('This :kind is marked deleted: it leaves every list and page, and its persistent IRI is kept, so nothing that cites it breaks. The audit log keeps everything it held.', ['kind' => __(\App\Relations\EntityKinds::noun($entity))]) }} <code>{{ $entity->iri }}</code></p>
    @if($blockers)
        <div class="error" role="alert">@foreach($blockers as $blocker)<p>{{ $blocker }}</p>@endforeach</div>
    @else
        @if($consequences['relations'] || $consequences['evidence'])
            <p>{{ __('Removed with it:') }}
                @if($consequences['relations']){{ __($consequences['relations'] === 1 ? ':count relation (and access points) it takes part in, with their assertions and dates' : ':count relations (and access points) it takes part in, with their assertions and dates', ['count' => $consequences['relations']]) }} @endif
                @if($consequences['relations'] && $consequences['evidence']) {{ __('and') }} @endif
                @if($consequences['evidence']){{ __($consequences['evidence'] === 1 ? ':count use of it as evidence of a relation' : ':count uses of it as evidence of a relation', ['count' => $consequences['evidence']]) }}@endif.</p>
        @endif
        <form method="post" action="{{ route('deletion.destroy', [$kind, $entity->getKey()]) }}">
            @csrf
            <button type="submit" style="background: var(--error)">{{ __('Delete :name', ['name' => $name]) }}</button>
        </form>
    @endif
@endsection
