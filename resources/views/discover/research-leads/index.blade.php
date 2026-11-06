{{-- OpenRiC Workspace - the published research leads (roadmap phase 17d4), after Heratio's research-leads/index (ahg-semantic-search). Needs $leads. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Research leads'))
@section('content')
    <h1>{{ __('Research leads') }}</h1>
    <p>{{ __('Connections across the collection that a model found and a curator thought worth following, each a question a researcher could pursue.') }}</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    @forelse($leads as $l)
        <article style="border-top: 1px solid #ccc; padding: .5rem 0"><h2 style="font-size: 1.1rem"><a href="{{ route('discover.research-leads.show', $l->id) }}">{{ $l->headline }}</a></h2>
            <p>{{ $l->why_it_matters }}</p><p class="meta">{{ __(\App\Scholarship\Scholarship::band($l->confidence)['label']) }}@if($l->ai_labelled) · {{ __('Drawn by AI') }}@endif</p></article>
    @empty<p>{{ __('No research leads are published yet.') }}</p>@endforelse
@endsection
