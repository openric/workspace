{{-- OpenRiC Workspace - the institution's DOIs in figures (Heratio's DOI report; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('DOI report'))
@section('content')
    <h1>{{ __('DOI report') }}</h1>
    @include('dois._nav')
    <h2>{{ __('By state') }}</h2>
    <table><tbody>@forelse($byState as $state => $n)<tr><th scope="row">{{ $state }}</th><td>{{ $n }}</td></tr>@empty<tr><td>{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <p class="meta">{{ trans_choice(':count registered with the test service.|:count registered with the test service.', $test, ['count' => $test]) }}</p>
    <h2>{{ __('Minted by month') }}</h2>
    <table><tbody>@forelse($byMonth as $month => $n)<tr><th scope="row">{{ $month }}</th><td>{{ $n }}</td></tr>@empty<tr><td>{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('By repository') }}</h2>
    <table><tbody>@forelse($byRepository as $id => $n)<tr><th scope="row">{{ $id ? \App\Relations\EntityKinds::label(\App\Models\Repository::find($id)) : __('none') }}</th><td>{{ $n }}</td></tr>@empty<tr><td>{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('Usage events') }}</h2>
    <table><tbody>@forelse($events as $status => $n)<tr><th scope="row">{{ $status }}</th><td>{{ $n }}</td></tr>@empty<tr><td>{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('What DataCite refused') }}</h2>
    <ul>@forelse($failures as $f)<li>{{ \Illuminate\Support\Carbon::parse($f->performed_at)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}: {{ json_decode($f->details, true)['error'] ?? '' }}</li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
@endsection
