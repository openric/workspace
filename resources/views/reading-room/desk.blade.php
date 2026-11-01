{{-- OpenRiC Workspace - the reading room desk for a day (roadmap phase 19b): its bookings to confirm, check in and out, its walk-ins, and the bookings waiting for confirmation (Heratio's bookings, walk-in and seat pages). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Reading room desk'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Reading room desk') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($rooms->isEmpty())<p>{{ __('No reading room yet.') }} <a href="{{ route('reading-room.rooms.create') }}">{{ __('Add one') }}</a></p>@else
    <form method="get" action="{{ route('reading-room.desk') }}" class="filters">
        <label>{{ __('Reading room') }} <select name="room">@foreach($rooms as $r)<option value="{{ $r->id }}" @selected($room?->id === $r->id)>{{ $r->name }}</option>@endforeach</select></label>
        <label>{{ __('Day') }} <input type="date" name="day" value="{{ $day }}"></label> <button type="submit">{{ __('Show') }}</button></form>
    <h2>{{ __('Bookings') }}</h2>
    @if($bookings->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Time') }}</th><th scope="col">{{ __('Researcher') }}</th><th scope="col">{{ __('Records') }}</th><th scope="col">{{ __('Seat') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@foreach($bookings as $b)<tr><td><a href="{{ route('research.bookings.show', $b) }}">{{ substr($b->start_time, 0, 5) }} - {{ substr($b->end_time, 0, 5) }}</a></td>
            <td>{{ $b->researcher->first_name }} {{ $b->researcher->last_name }}@if($b->researcher->card_number) <span class="meta">{{ $b->researcher->card_number }}</span>@endif</td>
            <td>{{ $b->materials->count() }}@if($b->materials->isNotEmpty()) ({{ $b->materials->countBy(fn ($m) => $m->status->label($lang))->map(fn ($n, $s) => "$s $n")->join(', ') }})@endif</td>
            <td>{{ $b->seat?->seat_label ?? $b->seat?->seat_number }}</td><td>{{ $b->status->label($lang) }}@if($b->checked_in_at) · {{ __('in') }} {{ $b->checked_in_at->format('H:i') }}@endif @if($b->checked_out_at) · {{ __('out') }} {{ $b->checked_out_at->format('H:i') }}@endif</td></tr>@endforeach</tbody></table>
    @endif
    @if($room)
    <h2>{{ __('Walk-ins') }}</h2>
    @forelse($walkIns as $w)<p>{{ $w->check_in_time->format('H:i') }} · {{ $w->first_name }} {{ $w->last_name }}@if($w->organization) · {{ $w->organization }}@endif @if($w->purpose) · {{ $w->purpose }}@endif
        @if($w->check_out_time) · {{ __('left at :time', ['time' => $w->check_out_time->format('H:i')]) }}@else <form method="post" action="{{ route('reading-room.walk-ins.leave', $w) }}" style="display: inline">@csrf<button type="submit">{{ __('Leaves') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($room->walk_ins_allowed)
        <details><summary>{{ __('A visitor without a booking') }}</summary>
            <form method="post" action="{{ route('reading-room.walk-ins.store', $room) }}">@csrf
                <p><label for="w-first">{{ __('First name') }}</label> <input id="w-first" name="first_name" required> <label for="w-last">{{ __('Surname') }}</label> <input id="w-last" name="last_name" required>
                    <label for="w-email">{{ __('Email') }}</label> <input id="w-email" name="email" type="email"> <label for="w-phone">{{ __('Telephone') }}</label> <input id="w-phone" name="phone"></p>
                <p><label for="w-idtype">{{ __('Identity document') }}</label> <select id="w-idtype" name="id_type"><option value="">-</option>@foreach($idTypes as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select> <label for="w-idno">{{ __('Its number') }}</label> <input id="w-idno" name="id_number">
                    <label for="w-org">{{ __('Organisation') }}</label> <input id="w-org" name="organization"></p>
                <p><label for="w-purpose">{{ __('Purpose') }}</label> <input id="w-purpose" name="purpose" size="40"> <label for="w-topic">{{ __('Research topic') }}</label> <input id="w-topic" name="research_topic" size="40"></p>
                @if($room->has_seat_management)<p><label for="w-seat">{{ __('Seat') }}</label> <select id="w-seat" name="seat_id"><option value="">-</option>@foreach($room->seats()->where('is_active', true)->orderBy('position')->get() as $s)<option value="{{ $s->id }}">{{ $s->seat_label ?? $s->seat_number }}</option>@endforeach</select></p>@endif
                <p><label><input type="checkbox" name="rules_acknowledged" value="1"> {{ __('The visitor accepts the reading room\'s rules') }}</label> <label><input type="checkbox" name="photo_permission" value="1"> {{ __('May photograph') }}</label></p>
                <button type="submit">{{ __('Check in') }}</button></form>
        </details>
    @endif
    @endif
    <h2>{{ __('Waiting for confirmation') }}</h2>
    @forelse($pending as $b)<p><a href="{{ route('research.bookings.show', $b) }}">{{ $b->booking_date->format('Y-m-d') }} {{ substr($b->start_time, 0, 5) }}</a> · {{ $b->room->name }} · {{ $b->researcher->first_name }} {{ $b->researcher->last_name }}
        <form method="post" action="{{ route('reading-room.bookings.step', [$b, 'confirm']) }}" style="display: inline">@csrf<button type="submit">{{ __('Confirm') }}</button></form></p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @endif
@endsection
