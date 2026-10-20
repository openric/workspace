{{-- OpenRiC Workspace - merge a duplicate authority record into another. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = $agent->described('agent-authorised-name', ['en']))
@section('title', 'Merge '.$name)
@section('content')
    <p><a href="{{ route('agents.show', $agent) }}">Back to {{ $name }}</a></p>
    <h1>Merge {{ $name }} into another authority record</h1>
    <p>For a duplicate. Its relations, names (as other forms of name), identifiers (a local one as a previous identifier) and contact points move to the record you keep; a relation the kept record already has is not made twice. {{ $name }} is then marked deleted, and its persistent IRI leads to the record kept.</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="get" action="{{ route('agents.merge-form', $agent) }}">
        <label for="q">Find the record to keep, by name or identifier</label>
        <input id="q" name="q" value="{{ $query }}" maxlength="200">
        <button type="submit">Find</button>
    </form>
    @if($query !== '')
        @if($candidates->isEmpty())
            <p>Nothing found.</p>
        @else
            <form method="post" action="{{ route('agents.merge', $agent) }}">
                @csrf
                <fieldset style="border: 0; padding: 0"><legend>Keep</legend>
                    @foreach($candidates as $candidate)
                        <label><input type="radio" name="into" value="{{ $candidate->id }}" required @checked($loop->first)> {{ \App\Relations\EntityKinds::label($candidate) }}, {{ \App\Relations\EntityKinds::noun($candidate) }}@if($candidate->identifier) ({{ $candidate->identifier }})@endif</label>
                    @endforeach
                </fieldset>
                <button type="submit" style="background: var(--error)">Merge {{ $name }} into it</button>
            </form>
        @endif
    @endif
@endsection
