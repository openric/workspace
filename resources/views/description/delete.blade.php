{{-- OpenRiC Workspace - confirm deleting an entity. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = \App\Relations\EntityKinds::label($entity))
@section('title', 'Delete '.$name)
@section('content')
    <p><a href="{{ \App\Relations\EntityKinds::route($entity) }}">Back to {{ $name }}</a></p>
    <h1>Delete {{ $name }}?</h1>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>This {{ \App\Relations\EntityKinds::noun($entity) }} is marked deleted: it leaves every list and page, and its persistent IRI (<code>{{ $entity->iri }}</code>{{ __(') is kept, so nothing that cites it breaks. The audit log keeps everything it held.') }}</p>
    @if($blockers)
        <div class="error" role="alert">@foreach($blockers as $blocker)<p>{{ $blocker }}</p>@endforeach</div>
    @else
        @if($consequences['relations'] || $consequences['evidence'])
            <p>Removed with it:
                @if($consequences['relations']){{ $consequences['relations'] }} {{ $consequences['relations'] === 1 ? 'relation' : 'relations' }} (and access points) it takes part in, with their assertions and dates @endif
                @if($consequences['relations'] && $consequences['evidence']) and @endif
                @if($consequences['evidence']){{ $consequences['evidence'] }} {{ $consequences['evidence'] === 1 ? 'use' : 'uses' }} of it as evidence of a relation @endif.</p>
        @endif
        <form method="post" action="{{ route('deletion.destroy', [$kind, $entity->getKey()]) }}">
            @csrf
            <button type="submit" style="background: var(--error)">Delete {{ $name }}</button>
        </form>
    @endif
@endsection
