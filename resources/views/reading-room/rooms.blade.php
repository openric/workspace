{{-- OpenRiC Workspace - reading rooms (Heratio's rooms list; roadmap phase 19b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Reading rooms'))
@section('content')
    <h1>{{ __('Reading rooms') }}</h1>
    <p><a href="{{ route('reading-room.rooms.create') }}">{{ __('Add a reading room') }}</a></p>
    @if($rooms->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Capacity') }}</th><th scope="col">{{ __('Seats') }}</th><th scope="col">{{ __('Equipment') }}</th><th scope="col">{{ __('Hours') }}</th><th scope="col">{{ __('Active') }}</th></tr></thead>
        <tbody>@foreach($rooms as $r)<tr><td><a href="{{ route('reading-room.rooms.edit', $r) }}">{{ $r->name }}</a></td><td>{{ $r->capacity }}</td><td>{{ $r->seats_count }}</td><td>{{ $r->equipment_count }}</td>
            <td>{{ substr($r->opening_time, 0, 5) }} - {{ substr($r->closing_time, 0, 5) }}</td><td>{{ $r->is_active ? __('Yes') : __('No') }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
