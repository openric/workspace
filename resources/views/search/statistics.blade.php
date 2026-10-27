{{-- OpenRiC Workspace - search statistics (roadmap phase 6; Heratio's search analytics, ahg-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Search statistics'))
@section('content')
    <h1>{{ __('Search statistics') }}</h1>
    <p>{{ __('What people searched for, in the public catalogue and in the Workspace. Nothing about who searched is kept.') }}</p>
    <p>@foreach([7, 30, 90, 365] as $d)@if($d === $days)<strong>{{ __('Last :days days', ['days' => $d]) }}</strong>@else<a href="{{ route('search.statistics', ['days' => $d]) }}">{{ __('Last :days days', ['days' => $d]) }}</a>@endif @if(! $loop->last) · @endif @endforeach</p>
    <p>{{ __(':total searches, :unique different, :zero found nothing; :public in the public catalogue.', ['total' => $totals['total'], 'unique' => $totals['unique_queries'], 'zero' => $totals['zero'], 'public' => $totals['public']]) }}</p>
    <h2>{{ __('Searched most') }}</h2>
    @if($top === [])<p>{{ __('None.') }}</p>@else
        <table><thead><tr><th>{{ __('Search') }}</th><th>{{ __('Count') }}</th><th>{{ __('Results on average') }}</th></tr></thead><tbody>
            @foreach($top as $row)<tr><td><a href="{{ route('search', ['q' => $row['query']]) }}">{{ $row['query'] }}</a></td><td>{{ $row['count'] }}</td><td>{{ $row['avg_results'] }}</td></tr>@endforeach
        </tbody></table>
    @endif
    <h2>{{ __('Found nothing') }}</h2>
    <p class="meta">{{ __('What people look for and the catalogue does not yet describe, or describes in other words.') }}</p>
    @if($zero === [])<p>{{ __('None.') }}</p>@else
        <table><thead><tr><th>{{ __('Search') }}</th><th>{{ __('Count') }}</th></tr></thead><tbody>
            @foreach($zero as $row)<tr><td>{{ $row['query'] }}</td><td>{{ $row['count'] }}</td></tr>@endforeach
        </tbody></table>
    @endif
@endsection
