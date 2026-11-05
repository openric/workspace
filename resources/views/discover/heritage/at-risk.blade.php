{{-- OpenRiC Workspace - the public register of heritage at risk with its figures (roadmap phase 17d3), after Heratio's endangered/dashboard and endangered/register (ahg-semantic-search). Needs $dashboard, $items, $risk, $risks. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Heritage at risk'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Heritage at risk') }}</h1>
    <p>{{ __('Heritage can be lost before it is ever recorded - to conflict, to a changing climate, to the slow decay of fragile materials, to lost funding, to displacement, or simply because no durable digital copy was ever made. This register draws together items that have been judged to need capture sooner rather than later, ordered by urgency, so the most vulnerable can be safeguarded first.') }}</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if($dashboard['total'] === 0)<h2>{{ __('No items flagged as at risk yet') }}</h2>@else
    <p><strong>{{ __('Items flagged at risk') }}:</strong> {{ $dashboard['total'] }} · <strong>{{ __('Still awaiting capture') }}:</strong> {{ $dashboard['outstanding'] }} · <strong>{{ __('Captured / safeguarded') }}:</strong> {{ $dashboard['captured'] }}
        · <strong>{{ __('Capture progress') }}:</strong> {{ $dashboard['capture_progress_pct'] }}%</p>
    <p class="meta">{{ __('Progress is the share of items captured out of those captured plus those still awaiting capture. Items no longer treated as at risk are not counted.') }}</p>
    <table><caption>{{ __('Risk') }}</caption><tbody>@foreach($dashboard['by_risk'] as $r)<tr><th scope="row">{{ $r['concept']->label($lang) }}</th><td>{{ $r['count'] }}</td></tr>@endforeach</tbody></table>
    <table><caption>{{ __('Urgency') }}</caption><tbody>@foreach($dashboard['by_urgency'] as $u)<tr><th scope="row">{{ $u['concept']->label($lang) }}</th><td>{{ $u['count'] }}</td></tr>@endforeach</tbody></table>
    @endif
    <h2>{{ __('Public at-risk register') }}</h2>
    <p>{{ __('Browse by risk:') }} @if($risk === null)<strong>{{ __('Show all categories') }}</strong>@else<a href="{{ route('discover.at-risk') }}">{{ __('Show all categories') }}</a>@endif
        @foreach($risks as $r) · @if($risk === $r->notation)<strong>{{ $r->label($lang) }}</strong>@else<a href="{{ route('discover.at-risk', ['risk' => $r->notation]) }}">{{ $r->label($lang) }}</a>@endif @endforeach</p>
    @if($items->isEmpty())<p>{{ $risk ? __('No published items at risk in this category') : __('No published items are currently flagged at risk') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Risk') }}</th><th scope="col">{{ __('Urgency') }}</th><th scope="col">{{ __('Capture status') }}</th><th scope="col">{{ __('Reason') }}</th></tr></thead>
        <tbody>@foreach($items as $i)<tr><td><a href="{{ route('discover.record', $i->record_resource_id) }}">{{ $view->label($i->record) }}</a></td><td>{{ $i->risk->label($lang) }}</td><td>{{ $i->urgency->label($lang) }}</td>
            <td>{{ $i->captureStatus->label($lang) }}</td><td>{{ \Illuminate\Support\Str::limit((string) $i->reason, 200) }}</td></tr>@endforeach</tbody></table>
    @endif
    <p><a href="{{ route('discover.at-risk.network') }}">{{ __('Heritage at risk across institutions') }}</a> · <a href="{{ route('discover.at-risk.json') }}">{{ __('Open data (JSON)') }}</a></p>
@endsection
