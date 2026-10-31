{{-- OpenRiC Workspace - the institution's embargoes (roadmap phase 13a, ADR 0015), after Heratio's embargo admin and EmbargoReportCommand: statistics, in force, ending within 30 days, all, and as CSV. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Embargoes'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Embargoes') }}</h1>
    <p><strong>{{ __('In force') }}:</strong> {{ $statistics['in_force'] }}
        @foreach($statistics['by_type'] as $type => $n) · {{ \App\Models\Concept::where('iri', \App\Rights\Embargoes::TYPES.$type)->first()?->label($lang) }}: {{ $n }}@endforeach
        · <strong>{{ __('Ending within 30 days') }}:</strong> {{ $statistics['ending'] }} · {{ __('All') }}: {{ $statistics['total'] }}</p>
    <p>@foreach(['in-force' => 'In force', 'ending' => 'Ending within 30 days', 'all' => 'All'] as $key => $label)@if($show === $key)<strong>{{ __($label) }}</strong>@else<a href="{{ route('embargoes.index', ['show' => $key]) }}">{{ __($label) }}</a>@endif · @endforeach
        <a href="{{ route('embargoes.index', ['show' => $show, 'format' => 'csv']) }}">CSV</a></p>
    @if($embargoes->isEmpty())<p>{{ __('None.') }}</p>@else
    <table>
        <thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('What it withholds') }}</th><th scope="col">{{ __('Reason') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Until') }}</th></tr></thead>
        <tbody>@foreach($embargoes as $e)
            <tr><td><a href="{{ route('records.show', $e->record) }}#embargoes">{{ \App\Relations\EntityKinds::label($e->record) }}</a></td><td>{{ $e->type->label($lang) }}</td><td>{{ $e->reason?->label($lang) }}</td>
                <td>{{ $e->starts_on->format('Y-m-d') }}</td><td>{{ $e->lifted_at ? __('lifted on :date', ['date' => $e->lifted_at->format('Y-m-d')]) : ($e->ends_on?->format('Y-m-d') ?? __('until lifted')) }}</td></tr>
        @endforeach</tbody>
    </table>@endif
@endsection
