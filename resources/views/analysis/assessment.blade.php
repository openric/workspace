{{-- OpenRiC Workspace - a record assessed as a source (Heratio's research_source_assessment; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Assess as a source').': '.\App\Relations\EntityKinds::label($record, \App\Support\Vocab::languages()))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record, $lang) }}</a></p>
    <h1>{{ __('Assess as a source') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @forelse($assessments as $a)
        <p><strong>{{ $a->sourceKind->label($lang) }}</strong> · {{ $a->sourceForm->label($lang) }} · {{ $a->completeness->label($lang) }}@if($a->trust_score !== null) · {{ __('trust :n of 100', ['n' => $a->trust_score]) }}@endif
            <span class="meta">{{ $a->user?->name }} · {{ $a->assessed_at->timezone(config('workspace.timezone'))->format('j M Y') }}</span>
            @if($a->rationale)<br>{{ $a->rationale }}@endif @if($a->bias_context)<br><em>{{ __('Bias') }}:</em> {{ $a->bias_context }}@endif</p>
    @empty<p>{{ __('Not yet assessed.') }}</p>@endforelse
    <h2>{{ __('Your assessment') }}</h2>
    <form method="post" action="{{ route('research.assess.store', $record) }}">@csrf
        @foreach(['source_kind' => [$kinds, 'Kind of source', 'primary'], 'source_form' => [$forms, 'Form', 'original'], 'completeness' => [$completeness, 'Completeness', 'complete']] as $f => [$list, $l, $d])
            <label for="as-{{ $f }}">{{ __($l) }}</label> <select id="as-{{ $f }}" name="{{ $f }}">@foreach($list as $c)<option value="{{ $c->notation }}" @selected($c->notation === $d)>{{ $c->label($lang) }}</option>@endforeach</select>
        @endforeach
        <label for="as-trust">{{ __('Trust (0 to 100)') }}</label> <input id="as-trust" name="trust_score" type="number" min="0" max="100" style="width: 5rem"><br>
        <label for="as-why">{{ __('Why') }}</label><br><textarea id="as-why" name="rationale" rows="2" cols="80"></textarea><br>
        <label for="as-bias">{{ __('Its bias and context') }}</label><br><textarea id="as-bias" name="bias_context" rows="2" cols="80"></textarea><br><button type="submit">{{ __('Save') }}</button></form>
@endsection
