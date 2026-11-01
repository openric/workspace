{{-- OpenRiC Workspace - proposals that two entities are one (Heratio's entity resolution; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Entity resolution'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Entity resolution') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>@foreach($statuses as $s)@if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('research.resolutions', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif · @endforeach <a href="{{ route('research.resolutions', ['status' => 'all']) }}">{{ __('All') }}</a></p>
    @forelse($rows as $row)
        @php($r = $row['resolution'])
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><a href="{{ \App\Relations\EntityKinds::route($row['entities'][0]) }}">{{ \App\Relations\EntityKinds::label($row['entities'][0], $lang) }}</a> = <a href="{{ \App\Relations\EntityKinds::route($row['entities'][1]) }}">{{ \App\Relations\EntityKinds::label($row['entities'][1], $lang) }}</a>?
                <span class="meta">{{ $r->status->label($lang) }}@if($r->confidence !== null) · {{ number_format($r->confidence, 2) }}@endif @if($r->match_method) · {{ $r->match_method }}@endif · {{ $r->proposer?->name }}@if($r->resolver) · {{ $r->resolver->name }}@endif</span></p>
            @if($r->notes)<p>{{ $r->notes }}</p>@endif
            @foreach((array) $r->evidence as $e)<p class="meta">{{ $e }}</p>@endforeach
            @if($row['claims']->isNotEmpty())<p>{{ __('Claims that relate the two') }}:@foreach($row['claims'] as $c)<br>{{ $c->subject_label }} <em>{{ $c->predicate }}</em> {{ $c->object_label }} <span class="meta">{{ $c->status->label($lang) }}</span>@endforeach</p>@endif
            @if($r->status->notation === 'proposed')
                @if($row['may'])<form method="post" action="{{ route('research.resolutions.step', ['accept', $r]) }}" style="display: inline">@csrf<input name="notes" size="30" aria-label="{{ __('Notes') }}"> <button type="submit">{{ __('They are one') }}</button>
                    <button type="submit" formaction="{{ route('research.resolutions.step', ['reject', $r]) }}">{{ __('They are two') }}</button></form>@endif
                @if($r->proposer_id === auth()->id())<form method="post" action="{{ route('research.resolutions.step', ['withdraw', $r]) }}" style="display: inline">@csrf<button type="submit">{{ __('Withdraw') }}</button></form>@endif
            @elseif($r->status->notation === 'accepted' && $row['entities'][0] instanceof \App\Models\Agent && $row['may'])
                <p><a href="{{ route('agents.merge-form', $row['entities'][1]) }}">{{ __('Merge the two authority records') }}</a></p>
            @endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <h2>{{ __('Propose that two are one') }}</h2>
    <form method="post" action="{{ route('research.resolutions.step', ['propose']) }}">@csrf
        <label for="er-a">{{ __('One (its persistent IRI)') }}</label> <input id="er-a" name="a" required size="45"><br>
        <label for="er-b">{{ __('The other (its persistent IRI)') }}</label> <input id="er-b" name="b" required size="45"><br>
        <label for="er-conf">{{ __('Confidence (0 to 1)') }}</label> <input id="er-conf" name="confidence" type="number" min="0" max="1" step="0.01" style="width: 5rem">
        <label for="er-method">{{ __('How you matched them') }}</label> <input id="er-method" name="match_method" size="30"><br>
        <label for="er-ev">{{ __('Evidence, one a line') }}</label><br><textarea id="er-ev" name="evidence" rows="3" cols="80"></textarea><br>
        <label for="er-notes">{{ __('Notes') }}</label> <input id="er-notes" name="notes" size="60"> <button type="submit">{{ __('Propose') }}</button></form>
@endsection
