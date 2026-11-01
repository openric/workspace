{{-- OpenRiC Workspace - the contradictions found among a project's claims (Heratio's contradiction engine; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Contradictions').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('reasoning._nav')
    <h1>{{ __('Contradictions') }}</h1>
    @if($level >= 2)<form method="post" action="{{ route('research.reasoning.contradictions.scan', $project) }}">@csrf<button type="submit">{{ __('Scan the claims') }}</button></form>@endif
    <p>@foreach($statuses as $s)@if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('research.reasoning.contradictions', [$project, 'status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif · @endforeach
        @if($status === 'all')<strong>{{ __('All') }}</strong>@else<a href="{{ route('research.reasoning.contradictions', [$project, 'status' => 'all']) }}">{{ __('All') }}</a>@endif</p>
    @forelse($findings as $f)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $f->kind->label($lang) }}</strong> · {{ $f->severity->label($lang) }} · <span class="meta">{{ $f->status->label($lang) }}</span></p>
            <p>{{ $f->detail }}</p>
            <p class="meta"><a href="{{ route('research.reasoning.claim', [$project, $f->claimA]) }}">{{ \Illuminate\Support\Str::limit($f->claimA->object_value, 80) }}</a>@if($f->claimB) · <a href="{{ route('research.reasoning.claim', [$project, $f->claimB]) }}">{{ \Illuminate\Support\Str::limit($f->claimB->object_value, 80) }}</a>@endif</p>
            @if($level >= 2)@foreach(['resolved' => 'Resolved', 'dismissed' => 'Not a contradiction', 'open' => 'Open again'] as $to => $l)@if($f->status->notation !== $to)<form method="post" action="{{ route('research.reasoning.contradictions.status', [$f, $to]) }}" style="display: inline">@csrf<button type="submit">{{ __($l) }}</button></form> @endif @endforeach @endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
@endsection
