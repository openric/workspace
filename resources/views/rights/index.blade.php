{{-- OpenRiC Workspace - the rights report (roadmap phase 2; Heratio's rights report, ahg-extended-rights). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Rights'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Rights') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('The rights in force today, the Labels applied and the rights holders, and what has no right recorded. Each list downloads as CSV.') }}</p>
    @if($mayAssign)<p><a href="{{ route('rights.batch') }}">{{ __('Give the archival descriptions on the clipboard a right or a Label') }}</a> ({{ $onClipboard }})</p>@endif
    @foreach(['statements' => __('By rights statement'), 'licences' => __('By licence'), 'bases' => __('By basis'), 'labels' => __('Traditional Knowledge and Biocultural Labels')] as $list => $heading)
        <h2>{{ $heading }} <small><a href="{{ route('rights.csv', $list) }}">CSV</a></small></h2>
        @if($report[$list]->isEmpty())<p>{{ __('None.') }}</p>@else
            <table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Count') }}</th></tr></thead><tbody>
                @foreach($report[$list] as $row)<tr><td><a href="{{ $row['concept']->iri }}" rel="noopener">{{ $row['concept']->label($lang) }}</a></td><td>{{ $row['count'] }}</td></tr>@endforeach
            </tbody></table>
        @endif
    @endforeach
    <h2>{{ __('Rights holders') }} <small><a href="{{ route('rights.csv', 'holders') }}">CSV</a></small></h2>
    @if($report['holders']->isEmpty())<p>{{ __('None.') }}</p>@else
        <ul>@foreach($report['holders'] as $row)<li><a href="{{ route('agents.show', $row['agent']) }}#rights-held">{{ \App\Relations\EntityKinds::label($row['agent']) }}</a> ({{ $row['count'] }})</li>@endforeach</ul>
    @endif
    <h2>{{ __('With no right in force') }} ({{ $report['without']->count() }}) <small><a href="{{ route('rights.csv', 'without') }}">CSV</a></small></h2>
    @if($report['without']->isEmpty())<p>{{ __('Every unit of description and instantiation has a right.') }}</p>@else
        <ul>@foreach($report['without']->take(100) as $entity)<li><a href="{{ \App\Relations\EntityKinds::route($entity) }}#rights">{{ \App\Relations\EntityKinds::label($entity) }}</a> <span class="meta">{{ __(\App\Relations\EntityKinds::noun($entity)) }}</span></li>@endforeach</ul>
        @if($report['without']->count() > 100)<p class="meta">{{ __('The first 100; the CSV has them all.') }}</p>@endif
    @endif
@endsection
