{{-- OpenRiC Workspace - the public register of repatriation claims (roadmap phase 17d2), after Heratio's repatriation/dashboard (ahg-semantic-search). Needs $dashboard, $disclaimer. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Repatriation'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Repatriation') }}</h1>
    <p>{{ __('This dashboard gathers, in one place, the repatriation claims documented in our register: how many there are, where the objects originate, the communities connected to them, and where each conversation currently stands. It is offered in a spirit of transparency and care - to make these dialogues visible and to honour the places and communities of origin.') }}</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if($dashboard['total'] === 0)
        <h2>{{ __('No repatriation claims recorded yet') }}</h2>
    @else
    <p><strong>{{ __('Documented claims') }}:</strong> {{ $dashboard['total'] }} · <strong>{{ __('Dialogues in progress') }}:</strong> {{ $dashboard['in_dialogue'] }}
        · <strong>{{ __('Under virtual return') }}:</strong> {{ $dashboard['virtual_return'] }} · <strong>{{ __('Physically returned') }}:</strong> {{ $dashboard['returned'] }}</p>
    <h2>{{ __('Where the dialogues stand') }}</h2>
    <p class="meta">{{ __('Each status is a neutral, factual stage in a documented conversation. None asserts ownership or a finding of wrongful removal.') }}</p>
    <table><tbody>@foreach($dashboard['by_status'] as $s)<tr><th scope="row">{{ $s['concept']->label($lang) }}</th><td>{{ $s['count'] }}</td></tr>@endforeach</tbody></table>
    <h2>{{ __('Places and communities of origin') }}</h2>
    @if($dashboard['by_origin'] === [] && $dashboard['by_community'] === [])<p>{{ __('No place or community of origin has been recorded against the claims yet.') }}</p>@else
    <h3>{{ __('By place of origin') }}</h3><ul>@foreach($dashboard['by_origin'] as $o)<li>{{ $o['value'] }} ({{ $o['count'] }})</li>@endforeach</ul>
    <h3>{{ __('By community') }}</h3><ul>@foreach($dashboard['by_community'] as $o)<li>{{ $o['value'] }} ({{ $o['count'] }})</li>@endforeach</ul>
    @endif
    <h2>{{ __('Recent activity') }}</h2>
    <ul>@forelse($dashboard['recent'] as $c)<li><a href="{{ route('discover.repatriation.show', $c->id) }}">{{ $view->label($c->record) ?? __('Claim') }}</a> · {{ $c->claimant_community ?: __('Unnamed') }} · {{ $c->status->label($lang) }}</li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    @endif
    <p><a href="{{ route('discover.displaced') }}">{{ __('Displaced heritage') }}</a> · <a href="{{ route('discover.repatriation.json') }}">{{ __('Open data (JSON)') }}</a></p>
@endsection
