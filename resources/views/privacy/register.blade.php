{{-- OpenRiC Workspace - a privacy register's entries (roadmap phase 18a, ADR 0016). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __($definition['title']))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($labels = [...array_map(fn ($s) => $s[1], $definition['fields']), 'reference' => 'Reference', 'status_id' => 'Status', 'due_on' => 'Due', 'signed_off_at' => 'Signed off', 'withdrawn_at' => 'Withdrawn'])
    <p><a href="{{ route('privacy.index') }}">{{ __('Privacy') }}</a></p>
    <h1>{{ __($definition['title']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p><a href="{{ route('privacy.create', $register) }}">{{ __('Add') }}</a></p>
    @if($entries->isEmpty())<p>{{ __('None yet.') }}</p>@else
    <table><thead><tr>@foreach($definition['list'] as $f)<th scope="col">{{ __($labels[$f] ?? $f) }}</th>@endforeach</tr></thead>
        <tbody>@foreach($entries as $entry)<tr>
            @foreach($definition['list'] as $f)<td>@if($loop->first)<a href="{{ route('privacy.show', [$register, $entry->id]) }}">@endif
                @include('privacy._value', ['entry' => $entry, 'field' => $f, 'spec' => $definition['fields'][$f] ?? match ($f) { 'status_id' => ['vocab'], 'due_on' => ['date'], 'signed_off_at', 'withdrawn_at' => ['datetime'], default => ['text'] }])
                @if($loop->first)</a>@endif</td>@endforeach
        </tr>@endforeach</tbody></table>@endif
@endsection
