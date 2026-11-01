{{-- OpenRiC Workspace - what is new in a saved search since its snapshot (Heratio's searchDiff; roadmap phase 19c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $search->name)
@section('content')
    <p><a href="{{ route('research.searches.index') }}">{{ __('Saved searches') }}</a></p>
    <h1>{{ $search->name }}</h1>
    @php($show = function (string $key) { [$table, $id] = explode(':', $key, 2); $kind = \App\Relations\EntityKinds::byTable($table); $entity = $kind ? \App\Relations\EntityKinds::get($kind)['class']::find($id) : null; return $entity; })
    <p>{{ trans_choice('{0} Nothing is unchanged.|{1} :count result is unchanged.|[2,*] :count results are unchanged.', $diff['unchanged'], ['count' => $diff['unchanged']]) }}</p>
    <h2>{{ __('New since the snapshot') }}</h2>
    @forelse($diff['added'] as $key)@php($e = $show($key))@if($e)<p><a href="{{ \App\Relations\EntityKinds::route($e) }}">{{ \App\Relations\EntityKinds::label($e) }}</a></p>@endif @empty<p>{{ __('None.') }}</p>@endforelse
    <h2>{{ __('Gone since the snapshot') }}</h2>
    @forelse($diff['removed'] as $key)@php($e = $show($key))<p>{{ $e ? \App\Relations\EntityKinds::label($e) : __('No longer in the catalogue') }}</p>@empty<p>{{ __('None.') }}</p>@endforelse
    <form method="post" action="{{ route('research.searches.step', [$search, 'snapshot']) }}">@csrf<button type="submit">{{ __('Take a new snapshot') }}</button></form>
@endsection
