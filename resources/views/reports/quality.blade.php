{{-- OpenRiC Workspace - data quality: how complete the archival descriptions are (roadmap phase 8; Heratio's data quality report, ahg-reports). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Data quality'))
@section('content')
    <h1>{{ __('Data quality') }}</h1>
    @include('reports._menu')
    <p>{{ __('How many of the :total archival descriptions have each ISAD(G) element, in any language.', ['total' => $quality['total']]) }}</p>
    <table><thead><tr><th>{{ __('Element') }}</th><th>{{ __('With it') }}</th><th>{{ __('Without it') }}</th></tr></thead><tbody>
        @foreach($quality['elements'] as $element)
            @php($share = $quality['total'] ? round(100 * $element['present'] / $quality['total']) : 0)
            <tr><td>{{ $element['number'] }} {{ $element['label'] }}</td>
                <td><span class="bar" style="width: {{ round(6 * $share / 100, 2) }}rem" aria-hidden="true"></span> {{ $element['present'] }} ({{ $share }}%)</td>
                <td>{{ $quality['total'] - $element['present'] }}</td></tr>
        @endforeach
    </tbody></table>
@endsection
