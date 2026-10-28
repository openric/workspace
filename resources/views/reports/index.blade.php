{{-- OpenRiC Workspace - the reports overview (roadmap phase 8; Heratio's reports dashboard, collections health and catalogue growth, ahg-reports). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Reports'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($o = $overview)
    <h1>{{ __('Reports') }}</h1>
    @include('reports._menu')
    <p>{{ __('What the catalogue holds, how much of it is published, and how it has grown. Published means the authorised name is published, so the public catalogue shows it.') }}</p>

    <h2>{{ __('Holdings') }}</h2>
    <table><thead><tr><th>{{ __('Kind') }}</th><th>{{ __('Count') }}</th><th>{{ __('Published') }}</th></tr></thead><tbody>
        @foreach(['record' => 'Archival descriptions', 'agent' => 'Authority records', 'repository' => 'Repositories', 'activity' => 'Activities', 'place' => 'Places', 'container' => 'Containers', 'instantiation' => 'Instantiations'] as $kind => $label)
            <tr><td>{{ __($label) }}</td><td>{{ $o['counts'][$kind]['total'] }}</td>
                <td>@if($o['counts'][$kind]['published'] === null)-@else{{ $o['counts'][$kind]['published'] }} ({{ $o['counts'][$kind]['total'] ? round(100 * $o['counts'][$kind]['published'] / $o['counts'][$kind]['total']) : 0 }}%)@endif</td></tr>
        @endforeach
    </tbody></table>
    <p>{{ __('Archival descriptions with a digital file: :count of :total.', ['count' => $o['digital'], 'total' => $o['counts']['record']['total']]) }}
        {{ __('Changed in the last seven days: :count.', ['count' => $o['week']]) }}</p>

    <h2>{{ __('Growth by month') }}</h2>
    @php($most = max(1, $o['months']->max(fn ($m) => max($m['record'], $m['agent']))))
    <table><thead><tr><th>{{ __('Month') }}</th><th>{{ __('Archival descriptions') }}</th><th>{{ __('Authority records') }}</th></tr></thead><tbody>
        @foreach($o['months'] as $month => $added)
            <tr><td>{{ $month }}</td>
                @foreach(['record', 'agent'] as $kind)<td><span class="bar" style="width: {{ round(6 * $added[$kind] / $most, 2) }}rem" aria-hidden="true"></span> {{ $added[$kind] }}</td>@endforeach
            </tr>
        @endforeach
    </tbody></table>

    <h2>{{ __('By level of description') }}</h2>
    @if($o['levels']->isEmpty())<p>{{ __('None.') }}</p>@else
        <table><thead><tr><th>{{ __('Level of description') }}</th><th>{{ __('Count') }}</th></tr></thead><tbody>
            @foreach($o['levels'] as $row)<tr><td><a href="{{ route('reports.descriptions', ['level' => $row['concept']->id]) }}">{{ $row['concept']->label($lang) }}</a></td><td>{{ $row['count'] }}</td></tr>@endforeach
        </tbody></table>
    @endif

    <h2>{{ __('By repository') }}</h2>
    @if($o['holders']->isEmpty())<p>{{ __('None.') }}</p>@else
        <table><thead><tr><th>{{ __('Repository') }}</th><th>{{ __('Archival descriptions') }}</th></tr></thead><tbody>
            @foreach($o['holders'] as $row)<tr><td><a href="{{ route('repositories.show', $row['repository']) }}">{{ \App\Relations\EntityKinds::label($row['repository']) }}</a></td><td>{{ $row['count'] }}</td></tr>@endforeach
        </tbody></table>
    @endif
@endsection
