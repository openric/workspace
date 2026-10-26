{{-- OpenRiC Workspace - the modification history of an entity. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = \App\Relations\EntityKinds::label($entity))
@section('title', __('History of :name', ['name' => $name]))
@section('content')
    <p><a href="{{ \App\Relations\EntityKinds::route($entity) }}">{{ __('Back to :name', ['name' => $name]) }}</a></p>
    <h1>{{ __('History of :name', ['name' => $name]) }}</h1>
    <p>{{ __('Every change to it and to its names, values, dates, identifiers, contact points and relations, newest first, from the tamper-evident audit log (at most 500).') }}</p>
    @php($parts = ['names' => 'name', 'description_values' => 'value', 'dates' => 'date', 'identifiers' => 'identifier', 'contact_points' => 'contact point', 'relations' => 'relation'])
    @forelse($rows as $row)
        @php($before = json_decode((string) $row->before, true) ?: [])
        @php($after = json_decode((string) $row->after, true) ?: [])
        @php($what = $parts[$row->subject_table] ?? __('record'))
        @php($fields = collect(array_keys($after ?: $before))->reject(fn ($k) => in_array($k, ['id', 'iri', 'institution_id', 'created_at', 'updated_at', 'subject_table', 'subject_id'], true)))
        <p style="margin: .4rem 0"><strong>{{ \Carbon\CarbonImmutable::parse($row->occurred_at)->format('Y-m-d H:i') }}</strong>, {{ $row->actor ?? __('the system') }}:
            {{ $row->action }} {{ $what }}@if($fields->isNotEmpty()) <small>({{ $fields->take(8)->join(', ') }})</small>@endif
            @if(isset($after['value']) || isset($before['value']))<br><small>{{ isset($before['value']) ? \Illuminate\Support\Str::limit((string) $before['value'], 120).' → ' : '' }}{{ isset($after['value']) ? \Illuminate\Support\Str::limit((string) $after['value'], 120) : 'removed' }}</small>@endif</p>
    @empty
        <p>{{ __('No changes recorded.') }}</p>
    @endforelse
@endsection
