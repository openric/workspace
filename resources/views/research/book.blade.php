{{-- OpenRiC Workspace - booking the reading room (Heratio's book; roadmap phase 19b): a room, a day and times, the records to have ready, and the room's rules. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Book the reading room'))
@section('content')
    <p><a href="{{ route('research.profile') }}">{{ __('Researcher') }}</a></p>
    <h1>{{ __('Book the reading room') }}</h1>
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($rooms->isEmpty())<p>{{ __('No reading room takes bookings yet.') }}</p>@else
    <form method="post" action="{{ route('research.book.store') }}">@csrf
        @foreach($rooms as $room)
            <details @if($loop->first) open @endif><summary>{{ $room->name }}@if($room->location) · {{ $room->location }}@endif</summary>
                <p>{{ __('Open :days from :from to :to; book up to :advance days ahead, for at most :hours hours.', ['days' => collect($room->days_open)->map(fn ($d) => \Carbon\CarbonImmutable::now()->startOfWeek()->addDays($d - 1)->translatedFormat('l'))->join(', '),
                    'from' => substr($room->opening_time, 0, 5), 'to' => substr($room->closing_time, 0, 5), 'advance' => $room->advance_booking_days, 'hours' => $room->max_booking_hours]) }}</p>
                @if($room->operating_hours)<p style="white-space: pre-line">{{ $room->operating_hours }}</p>@endif
                @if($room->rules)<div style="white-space: pre-line; border-left: 3px solid var(--line); padding-left: .5rem">{{ $room->rules }}</div>@endif
            </details>
        @endforeach
        <p><label for="b-room">{{ __('Reading room') }}</label> <select id="b-room" name="reading_room_id">@foreach($rooms as $room)<option value="{{ $room->id }}" @selected(old('reading_room_id') === $room->id)>{{ $room->name }}</option>@endforeach</select>
            <label for="b-date">{{ __('Day') }}</label> <input id="b-date" type="date" name="booking_date" required min="{{ now()->format('Y-m-d') }}" value="{{ old('booking_date') }}">
            <label for="b-start">{{ __('From') }}</label> <input id="b-start" type="time" name="start_time" required value="{{ old('start_time', '09:00') }}">
            <label for="b-end">{{ __('to') }}</label> <input id="b-end" type="time" name="end_time" required value="{{ old('end_time', '12:00') }}"></p>
        <p><label for="b-purpose">{{ __('Purpose of the visit') }}</label><br><textarea id="b-purpose" name="purpose" rows="2" cols="70">{{ old('purpose') }}</textarea></p>
        <fieldset><legend>{{ __('Records to have ready') }}</legend>
            @if($record)<label><input type="checkbox" name="records[]" value="{{ $record->id }}" checked> {{ \App\Relations\EntityKinds::label($record) }}</label><br>@endif
            @foreach($favourites->reject(fn ($f) => $f->id === $record?->id) as $f)<label><input type="checkbox" name="records[]" value="{{ $f->id }}"> {{ \App\Relations\EntityKinds::label($f) }}@if($code = $f->referenceCode()) <span class="meta">{{ $code }}</span>@endif</label><br>@endforeach
            <p><label for="b-iris">{{ __('Or their persistent IRIs, one a line') }}</label><br><textarea id="b-iris" name="iris" rows="3" cols="70">{{ old('iris') }}</textarea></p>
        </fieldset>
        <p><label for="b-notes">{{ __('Notes') }}</label><br><textarea id="b-notes" name="notes" rows="2" cols="70">{{ old('notes') }}</textarea></p>
        <p><label><input type="checkbox" name="rules_acknowledged" value="1"> {{ __('I have read the reading room\'s rules and will keep them') }}</label></p>
        <button type="submit">{{ __('Book') }}</button>
    </form>
    @endif
@endsection
