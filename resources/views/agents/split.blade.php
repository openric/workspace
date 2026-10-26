{{-- OpenRiC Workspace - split an authority record that describes two agents. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = $agent->described('agent-authorised-name', ['en']))
@section('title', 'Split '.$name)
@section('content')
    <p><a href="{{ route('agents.show', $agent) }}">Back to {{ $name }}</a></p>
    <h1>Split {{ $name }}</h1>
    <p>For a record that describes two agents. A new authority record takes the names and relations you choose; the first name chosen becomes its authorised name if none of them is one. {{ $name }} keeps the rest, and at least one authorised name.</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('agents.split', $agent) }}">
        @csrf
        <label for="type">{{ __('Type of entity of the new record') }}</label>
        <select id="type" name="type" style="font: inherit; padding: .5rem">
            @foreach($types as $t)<option value="{{ $t->notation }}" @selected($agent->typeNotation() === $t->notation)>{{ $t->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        <fieldset style="border: 0; padding: 0"><legend><strong>{{ __('Names it takes') }}</strong></legend>
            @foreach($agent->names as $n)
                <label><input type="checkbox" name="names[]" value="{{ $n->id }}"> {{ $n->nameType->label(\App\Support\Vocab::languages()) }}: <span lang="{{ $n->language }}">{{ $n->value }}</span></label>
            @endforeach
        </fieldset>
        <fieldset style="border: 0; padding: 0"><legend><strong>{{ __('Relations it takes') }}</strong></legend>
            @forelse($relations as $relation)
                <label><input type="checkbox" name="relations[]" value="{{ $relation->id }}"> {{ ucfirst($relation->phrase()) }} {{ \App\Relations\EntityKinds::label($relation->other) }}</label>
            @empty
                <p>{{ __('None.') }}</p>
            @endforelse
        </fieldset>
        <button type="submit">{{ __('Split') }}</button>
    </form>
@endsection
