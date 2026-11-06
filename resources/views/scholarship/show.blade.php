{{-- OpenRiC Workspace - one record's discovery and its links across federation peers (roadmap phase 17d4), after Heratio's scholarship (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Discoveries'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('scholarship.index') }}">{{ __('Discoveries') }}</a></p>
    <h1><a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record, $lang) }}</a></h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if($refusal)<p class="meta">{{ $refusal }}</p>@else<form method="post" action="{{ route('scholarship.discover', $record) }}">@csrf<button type="submit">{{ $discovery ? __('Discover again') : __('Discover connections') }}</button></form>@endif
    @if($discovery)
        <h2>{{ __('What the model found') }}</h2>
        <p>{{ __(\App\Scholarship\Scholarship::band($discovery->confidence)['label']) }} ({{ $discovery->confidence }}) · {{ $discovery->generated_at->format('Y-m-d H:i') }}@if($discovery->inference) · <a href="{{ route('ai.inference', $discovery->inference) }}">{{ __('Receipt') }}</a>@endif</p>
        @if($discovery->evidence['insights'] ?? [])<ul>@foreach($discovery->evidence['insights'] as $i)<li>{{ $i }}</li>@endforeach</ul>@else<p>{{ $discovery->summary }}</p>@endif
        <h2>{{ __('The verified links') }}</h2>
        @include('scholarship._connections', ['evidence' => $discovery->evidence])
        @if($lead)<p>{{ __('Research lead') }}: {{ $lead->status->label($lang) }} · <a href="{{ route('scholarship.leads') }}">{{ __('Research leads') }}</a></p>@endif
    @else<p>{{ __('No discovery yet.') }}</p>@endif
    <h2>{{ __('Across federation peers') }}</h2>
    <form method="post" action="{{ route('scholarship.federated', $record) }}">@csrf<button type="submit">{{ $federated ? __('Search the peers again') : __('Search the federation peers') }}</button></form>
    @if($federated)
        <p class="meta">{{ __('Searched for: :terms', ['terms' => implode('; ', $federated->terms)]) }} · {{ $federated->generated_at->format('Y-m-d H:i') }}</p>
        <ul>@foreach($federated->connections as $c)<li>@if($c['url'])<a href="{{ $c['url'] }}" rel="noopener nofollow">{{ $c['title'] }}</a>@else{{ $c['title'] }}@endif · {{ $c['peer_name'] }}
            @if($c['shared'])<br><small>{{ __('Shared access points: :terms', ['terms' => implode('; ', $c['shared'])]) }}</small>@endif @if($c['rationale'])<br><small>{{ $c['rationale'] }}</small>@endif</li>@endforeach</ul>
    @endif
@endsection
