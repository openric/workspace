{{-- OpenRiC Workspace - the network board of heritage at risk across federation peers (roadmap phase 17d3), after Heratio's endangered/global (ahg-semantic-search). Needs $board. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Heritage at risk across institutions'))
@section('content')
    <p><a href="{{ route('discover.at-risk') }}">{{ __('Back to the register') }}</a></p>
    <h1>{{ __('Heritage at risk across institutions') }}</h1>
    <p>{{ __('A shared race against loss: the most vulnerable heritage from this institution and its federation partners, gathered into one priority view.') }}</p>
    <p class="meta">{{ __('This board draws on registers maintained by curatorial staff at each institution. The order in which to act, and the assessment of risk, are matters for qualified staff to weigh against the evidence in every case.') }}</p>
    @if($board['peers'] === [] && $board['pushed_count'] === 0)<p>{{ __('No federation partners are configured; showing this institution only.') }}</p>@endif
    @if($board['warnings'] !== [])<details><summary>{{ __('Some partners could not be reached just now') }}</summary><ul>@foreach($board['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul></details>@endif
    @if($board['items'] === [])<p>{{ __('No items are currently flagged at risk across the network') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Partner institution') }}</th><th scope="col">{{ __('Risk') }}</th><th scope="col">{{ __('Urgency') }}</th><th scope="col">{{ __('Capture status') }}</th><th scope="col">{{ __('Trust') }}</th></tr></thead>
        <tbody>@foreach($board['items'] as $i)<tr><td>@if($i['catalogue_url'])<a href="{{ $i['catalogue_url'] }}" rel="noopener nofollow">{{ $i['title'] ?: $i['item_ref'] }}</a>@else{{ $i['title'] ?: $i['item_ref'] }}@endif</td>
            <td>{{ $i['source_peer'] === null ? __('This institution') : $i['institution'] }}@if(! empty($i['pushed'])) <small>({{ __('peer inbound') }})</small>@endif</td>
            <td>{{ $i['risk_label'] }}</td><td>{{ $i['urgency_label'] }}</td><td>{{ $i['capture_status_label'] }}</td>
            <td>@if($i['source_peer'] === null)-@elseif(in_array($i['source_peer']['verification'] ?? null, ['verified', 'pinned'], true)){{ __('verified') }}@else<span title="{{ __('This partner did not provide a verifiable signature for this data.') }}">{{ __('unverified') }}</span>@endif</td></tr>@endforeach</tbody></table>
    @endif
@endsection
