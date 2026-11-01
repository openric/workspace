{{-- OpenRiC Workspace - a reading room, its seats, retrieval runs and equipment (Heratio's editRoom, seats and equipment pages; roadmap phase 19b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $room?->name ?? __('Add a reading room'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('reading-room.rooms') }}">{{ __('Reading rooms') }}</a></p>
    <h1>{{ $room?->name ?? __('Add a reading room') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ $room ? route('reading-room.rooms.update', $room) : route('reading-room.rooms.store') }}" enctype="multipart/form-data">@csrf
        <p><label for="rm-name">{{ __('Name') }}</label> <input id="rm-name" name="name" required value="{{ old('name', $room?->name) }}" size="40"> <label for="rm-code">{{ __('Code') }}</label> <input id="rm-code" name="code" value="{{ old('code', $room?->code) }}" size="8">
            <label for="rm-repo">{{ __('Repository') }}</label> <select id="rm-repo" name="repository_id"><option value="">-</option>@foreach($repositories as $r)<option value="{{ $r->id }}" @selected($room?->repository_id === $r->id)>{{ $r->described('authorised-name', $lang) }}</option>@endforeach</select></p>
        <p><label for="rm-location">{{ __('Where it is') }}</label> <input id="rm-location" name="location" value="{{ old('location', $room?->location) }}" size="50"></p>
        <p><label for="rm-description">{{ __('Description') }}</label><br><textarea id="rm-description" name="description" rows="2" cols="70">{{ old('description', $room?->description) }}</textarea></p>
        <p><label for="rm-amenities">{{ __('Amenities') }}</label> <input id="rm-amenities" name="amenities" value="{{ old('amenities', $room?->amenities) }}" size="60"></p>
        <p><label for="rm-open">{{ __('Opens') }}</label> <input id="rm-open" type="time" name="opening_time" required value="{{ old('opening_time', substr($room?->opening_time ?? '09:00', 0, 5)) }}">
            <label for="rm-close">{{ __('Closes') }}</label> <input id="rm-close" type="time" name="closing_time" required value="{{ old('closing_time', substr($room?->closing_time ?? '16:30', 0, 5)) }}">
            {{ __('Days') }}: @foreach([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $n => $d)<label><input type="checkbox" name="days_open[]" value="{{ $n }}" @checked(in_array($n, (array) ($room?->days_open ?? [1, 2, 3, 4, 5]), true))> {{ __($d) }}</label> @endforeach</p>
        <p><label for="rm-hours">{{ __('Opening hours, as the public is told') }}</label><br><textarea id="rm-hours" name="operating_hours" rows="2" cols="70">{{ old('operating_hours', $room?->operating_hours) }}</textarea></p>
        <p><label for="rm-capacity">{{ __('Capacity') }}</label> <input id="rm-capacity" type="number" min="0" name="capacity" value="{{ old('capacity', $room?->capacity ?? 10) }}" style="width: 5rem">
            <label><input type="hidden" name="has_seat_management" value="0"><input type="checkbox" name="has_seat_management" value="1" @checked($room?->has_seat_management)> {{ __('Give each researcher a seat') }}</label>
            <label><input type="hidden" name="walk_ins_allowed" value="0"><input type="checkbox" name="walk_ins_allowed" value="1" @checked($room?->walk_ins_allowed ?? true)> {{ __('Takes walk-ins') }}</label>
            <label for="rm-walkins">{{ __('at most') }}</label> <input id="rm-walkins" type="number" min="0" name="walk_in_capacity" value="{{ old('walk_in_capacity', $room?->walk_in_capacity ?? 2) }}" style="width: 5rem"></p>
        <p><label for="rm-advance">{{ __('Booked at most (days ahead)') }}</label> <input id="rm-advance" type="number" min="0" name="advance_booking_days" value="{{ old('advance_booking_days', $room?->advance_booking_days ?? 14) }}" style="width: 5rem">
            <label for="rm-max">{{ __('for at most (hours)') }}</label> <input id="rm-max" type="number" min="1" max="24" name="max_booking_hours" value="{{ old('max_booking_hours', $room?->max_booking_hours ?? 8) }}" style="width: 5rem">
            <label for="rm-cancel">{{ __('Cancelled up to (hours before)') }}</label> <input id="rm-cancel" type="number" min="0" name="cancellation_hours" value="{{ old('cancellation_hours', $room?->cancellation_hours ?? 24) }}" style="width: 5rem"></p>
        <p><label for="rm-rules">{{ __('Rules') }}</label><br><textarea id="rm-rules" name="rules" rows="5" cols="70">{{ old('rules', $room?->rules) }}</textarea></p>
        <p><label for="rm-plan">{{ __('Floor plan') }}</label> <input id="rm-plan" type="file" name="floor_plan" accept="image/*">@if($room?->floorPlan) <a href="{{ route('research.files.show', $room->floorPlan) }}">{{ __('the present one') }}</a>@endif
            <label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($room?->is_active ?? true)> {{ __('Active') }}</label></p>
        <button type="submit">{{ __('Save') }}</button></form>

    @if($room)
        <h2>{{ __('Seats') }}</h2>
        <p>@forelse($room->seats->sortBy('position') as $s)<form method="post" action="{{ route('reading-room.seats.toggle', $s) }}" style="display: inline">@csrf<button type="submit" title="{{ $s->seatType->label($lang) }}" @class(['meta' => ! $s->is_active])>{{ $s->seat_label ?? $s->seat_number }}@unless($s->is_active) ({{ __('off') }})@endunless</button></form> @empty{{ __('None.') }}@endforelse</p>
        <details><summary>{{ __('Add seats') }}</summary>
            <form method="post" action="{{ route('reading-room.seats.store', $room) }}">@csrf
                <label for="st-count">{{ __('How many') }}</label> <input id="st-count" type="number" name="count" min="1" max="200" value="1" style="width: 5rem">
                <label for="st-prefix">{{ __('Numbered after') }}</label> <input id="st-prefix" name="prefix" size="6" placeholder="A">
                <label for="st-type">{{ __('Kind') }}</label> <select id="st-type" name="seat_type">@foreach($seatTypes as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
                <label for="st-zone">{{ __('Zone') }}</label> <input id="st-zone" name="zone" size="12">
                @foreach(['has_power' => 'Power', 'has_lamp' => 'Lamp', 'has_computer' => 'Computer', 'has_magnifier' => 'Magnifier'] as $f => $l)<label><input type="checkbox" name="{{ $f }}" value="1"> {{ __($l) }}</label> @endforeach
                <button type="submit">{{ __('Add') }}</button></form>
        </details>

        <h2>{{ __('Retrieval runs') }}</h2>
        @forelse($room->schedules->sortBy('retrieval_time') as $sc)<p>{{ $sc->name }} · {{ $sc->day_of_week ? __([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][$sc->day_of_week]) : __('every day it opens') }} · {{ substr($sc->retrieval_time, 0, 5) }} · {{ __(':minutes minutes cut-off', ['minutes' => $sc->cutoff_minutes_before]) }}@if($sc->storage_location) · {{ $sc->storage_location }}@endif
            <form method="post" action="{{ route('reading-room.schedules.remove', $sc) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></p>
        @empty<p>{{ __('None: requests are fetched as they come.') }}</p>@endforelse
        <details><summary>{{ __('Add a retrieval run') }}</summary>
            <form method="post" action="{{ route('reading-room.schedules.store', $room) }}">@csrf
                <label for="sc-name">{{ __('Name') }}</label> <input id="sc-name" name="name" required placeholder="{{ __('Morning retrieval') }}">
                <label for="sc-day">{{ __('Day') }}</label> <select id="sc-day" name="day_of_week"><option value="">{{ __('Every day it opens') }}</option>@foreach([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $n => $d)<option value="{{ $n }}">{{ __($d) }}</option>@endforeach</select>
                <label for="sc-time">{{ __('At') }}</label> <input id="sc-time" type="time" name="retrieval_time" required value="10:00">
                <label for="sc-cutoff">{{ __('Cut-off (minutes before)') }}</label> <input id="sc-cutoff" type="number" name="cutoff_minutes_before" min="0" value="30" style="width: 5rem">
                <label for="sc-max">{{ __('At most (items)') }}</label> <input id="sc-max" type="number" name="max_items_per_run" min="1" style="width: 5rem">
                <label for="sc-store">{{ __('From the store') }}</label> <input id="sc-store" name="storage_location" size="16"> <button type="submit">{{ __('Add') }}</button></form>
        </details>

        <h2>{{ __('Equipment') }}</h2>
        @foreach($room->equipment as $e)
            <details><summary>{{ $e->name }} · {{ $e->equipmentType->label($lang) }} · {{ $e->condition->label($lang) }}@unless($e->is_available) ({{ __('not available') }})@endunless @if($e->next_maintenance_on) · {{ __('maintenance due :date', ['date' => $e->next_maintenance_on->format('Y-m-d')]) }}@endif</summary>
                <form method="post" action="{{ route('reading-room.equipment.update', [$room, $e]) }}">@csrf @include('reading-room._equipment-fields', ['e' => $e, 'p' => 'eq'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('reading-room.equipment.maintain', $e) }}">@csrf
                    <label for="mt-{{ $e->id }}">{{ __('Maintenance done') }}</label> <input id="mt-{{ $e->id }}" name="description" required size="40">
                    <select name="condition" aria-label="{{ __('Condition after') }}">@foreach($conditions as $c)<option value="{{ $c->notation }}" @selected($e->condition->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
                    <input type="date" name="next_maintenance_on" aria-label="{{ __('Next maintenance') }}"> <button type="submit">{{ __('Record') }}</button></form>
                @foreach($e->maintenance as $m)<p><small>{{ $m->performed_at->format('Y-m-d') }} · {{ $m->description }} · {{ $m->condition_before }} to {{ $m->condition_after }}@if($m->performedBy) · {{ $m->performedBy->name }}@endif</small></p>@endforeach
            </details>
        @endforeach
        <details><summary>{{ __('Add equipment') }}</summary>
            <form method="post" action="{{ route('reading-room.equipment.store', $room) }}">@csrf @include('reading-room._equipment-fields', ['e' => null, 'p' => 'eq-new'])<button type="submit">{{ __('Add') }}</button></form></details>
    @endif
@endsection
