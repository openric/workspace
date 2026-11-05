{{-- OpenRiC Workspace - the collection over time, by century and decade (Heratio's collection timeline; roadmap phase 17d1). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('The collection over time'))
@section('content')
    <p><a href="{{ route('discover.ways-in') }}">{{ __('Ways into the collection') }}</a></p>
    <h1>{{ __('The collection over time') }}</h1>
    <p>{{ __('Each description counted at its earliest date. Choose a decade for the descriptions of those years.') }}</p>
    @php($max = max(1, collect($timeline['centuries'])->flatMap(fn ($c) => $c['decades'])->max() ?? 1))
    @forelse($timeline['centuries'] as $century => $c)
        <h2>{{ __(':from to :to', ['from' => $century, 'to' => $century + 99]) }} <span class="meta">{{ $c['n'] }}</span></h2>
        <table><tbody>@foreach($c['decades'] as $decade => $n)<tr><th scope="row"><a href="{{ route('discover.ways-in.decade', $decade) }}">{{ __(':from to :to', ['from' => $decade, 'to' => $decade + 9]) }}</a></th>
            <td style="width: 70%"><span style="display: inline-block; height: .8rem; background: var(--accent, #10373E); width: {{ max(2, round($n / $max * 100)) }}%" aria-hidden="true"></span> {{ $n }}</td></tr>@endforeach</tbody></table>
    @empty
        <p>{{ __('No published description has a date yet.') }}</p>
    @endforelse
    @if($timeline['undated'])<p class="meta">{{ trans_choice(':count description has no date.|:count descriptions have no date.', $timeline['undated'], ['count' => $timeline['undated']]) }}</p>@endif
@endsection
