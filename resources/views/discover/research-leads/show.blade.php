{{-- OpenRiC Workspace - one published research lead (roadmap phase 17d4), after Heratio's research-leads/show (ahg-semantic-search). Needs $lead, $unit, $links. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $lead->headline)
@section('content')
    <p><a href="{{ route('discover.research-leads') }}">{{ __('Research leads') }}</a></p>
    <h1>{{ $lead->headline }}</h1>
    <p class="meta">{{ __(\App\Scholarship\Scholarship::band($lead->confidence)['label']) }} · {{ trans_choice(':count connection|:count connections', $lead->connection_count, ['count' => $lead->connection_count]) }}@if($lead->ai_labelled) · {{ __('Drawn by AI') }}@endif</p>
    <h2>{{ __('Why it might matter') }}</h2>
    <p>{{ $lead->why_it_matters }}</p>
    @if($lead->lead_text)<h2>{{ __('What the model found') }}</h2><p style="white-space: pre-line">{{ $lead->lead_text }}</p>@endif
    <h2>{{ __('The verified links') }}</h2>
    @include('scholarship._connections', ['evidence' => $lead->evidence])
    <p><a href="{{ route('discover.record', $unit->id) }}">{{ __('Open the record') }}</a></p>
    <p class="meta">{{ __($disclaimer) }}</p>
@endsection
