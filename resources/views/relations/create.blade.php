{{-- OpenRiC Workspace - relate an entity to another. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Add a relation')
@section('content')
    @php($name = \App\Relations\EntityKinds::label($subject))
    <p><a href="{{ \App\Relations\EntityKinds::route($subject) }}">{{ $name }}</a></p>
    <h1>Add a relation to {{ $name }}</h1>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="get" action="{{ route('relations.create', [$kind, $subject->getKey()]) }}">
        <label for="relate">{{ $name }}</label>
        <select id="relate" name="relate" required style="font: inherit; padding: .5rem">
            @foreach($options as $option)
                <option value="{{ $option['type'].'|'.$option['side'] }}" @selected($chosen && $chosen['type'].'|'.$chosen['side'] === $option['type'].'|'.$option['side'])>{{ $option['phrase'] }} ({{ \App\Models\Concept::where('iri', \App\Support\Vocab::BASE.'relation-type/'.$option['type'])->first()?->label(['en', 'und']) }})</option>
            @endforeach
        </select>
        <label for="q">Find the other entity by name or identifier</label>
        <input id="q" name="q" value="{{ $query }}" maxlength="200">
        <button type="submit">Find</button>
    </form>

    @if($chosen)
        <h2>Results</h2>
        @if($candidates->isEmpty())
            <p>Nothing found. Try part of a name or an identifier.</p>
        @else
        <form method="post" action="{{ route('relations.store', [$kind, $subject->getKey()]) }}">
            @csrf
            <input type="hidden" name="relate" value="{{ $chosen['type'].'|'.$chosen['side'] }}">
            <fieldset style="border: 0; padding: 0"><legend>{{ $name }} {{ $chosen['phrase'] }}</legend>
                @foreach($candidates as $candidate)
                    @php($candidateKind = \App\Relations\EntityKinds::of($candidate))
                    <label><input type="radio" name="other" value="{{ $candidateKind.':'.$candidate->getKey() }}" required @checked($loop->first)>
                        {{ \App\Relations\EntityKinds::label($candidate) }}@if($candidate->identifier) ({{ $candidate->identifier }})@endif, {{ $candidateKind === 'record' ? 'unit of description' : $candidateKind }}</label>
                @endforeach
            </fieldset>
            @include('description._relation-fields', ['prefix' => 'new', 'relation' => null, 'withRole' => (bool) (config('workspace.relation_types.'.$chosen['type'])['role'] ?? false)])
            <label for="basis">On what basis do you assert it (optional)</label>
            <textarea id="basis" name="basis" rows="2" style="width: 100%; font: inherit; box-sizing: border-box">{{ old('basis') }}</textarea>
            <label for="basis_language">Language of the basis (BCP 47)</label>
            <input id="basis_language" name="basis_language" value="{{ old('basis_language') }}" maxlength="35" placeholder="en">
            <button type="submit">Add relation</button>
        </form>
        @endif
    @endif
@endsection
