{{-- OpenRiC Workspace - signs of a research project's impact (Heratio's impact tracking page; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Impact').' - '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('projects._part-head')
    <h1>{{ __('Impact') }}</h1>
    @if(session()->has('raised'))<p role="status">{{ trans_choice(':count new sign found.|:count new signs found.', session('raised'), ['count' => session('raised')]) }}</p>@endif
    <p class="meta">{{ trans_choice(':count citation|:count citations', $counts['citations'], ['count' => $counts['citations']]) }}
        @foreach($types as $t)@if(($counts['by_type'][$t->notation] ?? 0) && $t->notation !== 'citation') · {{ $t->label($lang) }}: {{ $counts['by_type'][$t->notation] }}@endif @endforeach
        @if($counts['last_scan']) · {{ __('Last found :date', ['date' => \Illuminate\Support\Carbon::parse($counts['last_scan'])->timezone(config('workspace.timezone'))->format('j M Y H:i')]) }}@endif</p>
    @if($dois)
        <p>{{ __('Published outputs looked up') }}: @foreach($dois as $doi => $o)<a href="https://doi.org/{{ $doi }}" rel="noopener noreferrer">{{ $doi }}</a>@if($o) ({{ $o->title }})@endif @if(! $loop->last), @endif @endforeach</p>
        @if($level >= 2)<form method="post" action="{{ route('research.impact.step', [$project, 'scan']) }}">@csrf<button type="submit">{{ __('Look in OpenAlex and Crossref now') }}</button></form>@endif
    @else
        <p>{{ __('No output is published with a DOI yet, so there is nothing to look up. Signs can still be recorded by hand.') }}</p>
    @endif
    <ul>
        @forelse($signals as $s)
            <li>{{ $s->signalType->label($lang) }}: @if($s->url)<a href="{{ $s->url }}" rel="noopener noreferrer">{{ $s->title }}</a>@else{{ $s->title }}@endif
                @if($s->source !== 'openalex-summary' && $s->detail) - {{ $s->detail }}@endif @if($s->output) ({{ $s->output->title }})@endif
                <span class="meta">{{ $s->source === 'manual' ? __('recorded by hand') : ($s->source === 'crossref-event' ? 'Crossref' : 'OpenAlex') }}@if($s->detected_at), {{ $s->detected_at->format('j M Y') }}@endif</span>
                @if($level >= 3)<form method="post" action="{{ route('research.funding.part.remove', ['impact', $s->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</li>
        @empty
            <li>{{ __('No sign of impact is recorded.') }}</li>
        @endforelse
    </ul>
    @if($level >= 2)<details><summary>{{ __('Record a sign by hand') }}</summary><form method="post" action="{{ route('research.impact.step', [$project, 'record']) }}">@csrf
        <label for="i-title">{{ __('What') }}</label> <input id="i-title" name="title" required size="50">
        <label for="i-type">{{ __('Kind') }}</label> <select id="i-type" name="signal_type">@foreach($types as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select><br>
        <label for="i-url">{{ __('Web address') }}</label> <input id="i-url" name="url" type="url" size="50">
        <label for="i-date">{{ __('Date') }}</label> <input id="i-date" name="detected_at" type="date">
        <label for="i-output">{{ __('Output') }}</label> <select id="i-output" name="output_id"><option value="">{{ __('The project as a whole') }}</option>@foreach($outputs as $o)<option value="{{ $o->id }}">{{ $o->title }}</option>@endforeach</select><br>
        <label for="i-detail">{{ __('Detail') }}</label><br><textarea id="i-detail" name="detail" rows="2" cols="70"></textarea><br>
        <button type="submit">{{ __('Record') }}</button></form></details>@endif
@endsection
