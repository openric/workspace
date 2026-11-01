{{-- OpenRiC Workspace - a project's argument builder (Heratio's argument builder; roadmap phase 19g): nine steps from problem to implication, each on a claim, with its warnings. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Argument builder').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('studios._nav')
    <h1>{{ __('Argument builder') }}</h1>
    @if($level >= 2)<form method="post" action="{{ route('research.studios.argument.step', [$project, 'save']) }}">@csrf<label for="ag-title">{{ __('Title') }}</label> <input id="ag-title" name="title" size="50" value="{{ $argument?->title }}"><br>
        <label for="ag-thesis">{{ __('Central thesis') }}</label><br><textarea id="ag-thesis" name="central_thesis" rows="3" cols="90">{{ $argument?->central_thesis }}</textarea><br><button type="submit">{{ __('Save') }}</button></form>
    @elseif($argument)<p><strong>{{ $argument->title }}</strong></p><p>{{ $argument->central_thesis }}</p>@endif
    @if($warnings)<h2>{{ __('Warnings') }}</h2>@foreach($warnings as $w)<p @class(['error' => $w['severity'] !== 'info'])>{{ $w['message'] }}</p>@endforeach @endif
    <h2>{{ __('Steps') }}</h2>
    @forelse($steps as $s)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $s->slot->label($lang) }}</strong>: @if($s->claim){{ $s->claim->object_value }} <span class="meta">{{ $s->claim->status->label($lang) }}</span>@else<span class="meta">{{ __('No claim yet') }}</span>@endif @if($s->note)<br><em>{{ $s->note }}</em>@endif</p>
            @if($level >= 2)<form method="post" action="{{ route('research.studios.argument.step', [$project, 'update', $s]) }}" style="display: inline">@csrf
                <select name="claim_id" aria-label="{{ __('Claim') }}"><option value="">{{ __('None') }}</option>@foreach($claims as $c)<option value="{{ $c->id }}" @selected($s->claim_id === $c->id)>{{ \Illuminate\Support\Str::limit($c->object_value, 60) }}</option>@endforeach</select>
                <input name="note" size="25" value="{{ $s->note }}" aria-label="{{ __('Note') }}"> <button type="submit">{{ __('Save') }}</button></form>
                @foreach(['up' => 'Up', 'down' => 'Down', 'remove' => 'Remove'] as $k => $l)<form method="post" action="{{ route('research.studios.argument.step', [$project, $k, $s]) }}" style="display: inline">@csrf<button type="submit">{{ __($l) }}</button></form> @endforeach @endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<h2>{{ __('Add a step') }}</h2><form method="post" action="{{ route('research.studios.argument.step', [$project, 'add']) }}">@csrf
        <label for="as-slot">{{ __('Step') }}</label> <select id="as-slot" name="slot">@foreach($slots as $s)<option value="{{ $s->notation }}" title="{{ $s->label($lang) }}">{{ $s->label($lang) }}</option>@endforeach</select>
        <label for="as-claim">{{ __('Claim') }}</label> <select id="as-claim" name="claim_id"><option value="">{{ __('None yet') }}</option>@foreach($claims as $c)<option value="{{ $c->id }}">{{ \Illuminate\Support\Str::limit($c->object_value, 60) }}</option>@endforeach</select>
        <label for="as-note">{{ __('Note') }}</label> <input id="as-note" name="note" size="30"> <button type="submit">{{ __('Add') }}</button></form>@endif
@endsection
