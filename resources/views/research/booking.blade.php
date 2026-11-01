{{-- OpenRiC Workspace - a reading room booking (roadmap phase 19b, ADR 0017), after Heratio's viewBooking (ahg-research): the visit, the records asked for with where each is and its messages, the equipment booked; for staff, the desk's actions, each request's steps, custody and history. Needs $booking, $staff, $lang. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Booking for :date', ['date' => $booking->booking_date->format('j F Y')]))
@section('content')
    @php($state = $booking->status->notation)
    <p>@if($staff)<a href="{{ route('reading-room.desk', ['room' => $booking->reading_room_id, 'day' => $booking->booking_date->format('Y-m-d')]) }}">{{ __('The desk') }}</a>@else<a href="{{ route('research.profile') }}">{{ __('Researcher') }}</a>@endif</p>
    <h1>{{ __('Booking for :date', ['date' => $booking->booking_date->format('j F Y')]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p><strong>{{ $booking->status->label($lang) }}</strong> · {{ $booking->room->name }} · {{ substr($booking->start_time, 0, 5) }} - {{ substr($booking->end_time, 0, 5) }}
        @if($booking->seat) · {{ __('seat :seat', ['seat' => $booking->seat->seat_label ?? $booking->seat->seat_number]) }}@endif
        @if($booking->checked_in_at) · {{ __('checked in at :time', ['time' => $booking->checked_in_at->format('H:i')]) }}@endif
        @if($booking->checked_out_at) · {{ __('checked out at :time', ['time' => $booking->checked_out_at->format('H:i')]) }}@endif</p>
    @if($staff)<p>{{ __('Researcher') }}: <a href="{{ route('reading-room.researchers.show', $booking->researcher) }}">{{ $booking->researcher->first_name }} {{ $booking->researcher->last_name }}</a>@if($booking->researcher->card_number) ({{ $booking->researcher->card_number }})@endif</p>@endif
    @if($booking->purpose)<p>{{ __('Purpose') }}: {{ $booking->purpose }}</p>@endif
    @if($booking->researcher->user_id === auth()->id())<p>@include('research._project-select', ['thing' => $booking, 'kind' => 'booking'])</p>@endif
    @if($booking->notes)<p>{{ __('Notes') }}: {{ $booking->notes }}</p>@endif
    @if($booking->cancellation_reason)<p>{{ __('Cancelled because: :reason', ['reason' => $booking->cancellation_reason]) }}</p>@endif

    @if($staff)
        <p>
            @if($state === 'pending')<form method="post" action="{{ route('reading-room.bookings.step', [$booking, 'confirm']) }}" style="display: inline">@csrf<button type="submit">{{ __('Confirm') }}</button></form>@endif
            @if(in_array($state, ['pending', 'confirmed'], true) && ! $booking->checked_in_at)
                <form method="post" action="{{ route('reading-room.bookings.step', [$booking, 'check-in']) }}" style="display: inline">@csrf
                    @if($booking->room->has_seat_management)<select name="seat_id" aria-label="{{ __('Seat') }}"><option value="">{{ __('First free seat') }}</option>@foreach($booking->room->seats->where('is_active', true) as $s)<option value="{{ $s->id }}">{{ $s->seat_label ?? $s->seat_number }}</option>@endforeach</select>@endif
                    <button type="submit">{{ __('Check in') }}</button></form>
                <form method="post" action="{{ route('reading-room.bookings.step', [$booking, 'no-show']) }}" style="display: inline">@csrf<button type="submit">{{ __('No show') }}</button></form>
            @endif
            @if($booking->checked_in_at && ! $booking->checked_out_at)<form method="post" action="{{ route('reading-room.bookings.step', [$booking, 'check-out']) }}" style="display: inline">@csrf<button type="submit">{{ __('Check out') }}</button></form>@endif
        </p>
    @endif
    @if(in_array($state, ['pending', 'confirmed'], true) && ! $booking->checked_in_at)
        <details><summary>{{ __('Cancel the booking') }}</summary>
            <form method="post" action="{{ route('research.bookings.cancel', $booking) }}">@csrf<label for="c-reason">{{ __('Why') }}</label> <input id="c-reason" name="reason" size="50"> <button type="submit">{{ __('Cancel the booking') }}</button></form>
            @unless($staff)<p class="meta">{{ __('Up to :hours hours before the visit.', ['hours' => $booking->room->cancellation_hours]) }}</p>@endunless
        </details>
    @endif

    <h2>{{ __('Records asked for') }}</h2>
    @forelse($booking->materials as $m)
        <section style="border-top: 1px solid var(--line); padding: .4rem 0">
            <p><strong>{{ \App\Relations\EntityKinds::label($m->record, $lang) }}</strong>@if($code = $m->record->referenceCode()) <span class="meta">{{ $code }}</span>@endif ·
                {{ $m->status->label($lang) }} · {{ $m->priority->label($lang) }}
                @if($m->retrieval_scheduled_for) · {{ __('fetched in the run of :time', ['time' => $m->retrieval_scheduled_for->format('Y-m-d H:i')]) }}@endif
                @if($m->unavailable_reason)<br>{{ __('Unavailable: :reason', ['reason' => $m->unavailable_reason]) }}@endif
                @if($staff && ($m->location_code || $m->box_number || $m->shelf_location))<br><small>{{ collect([$m->location_code, $m->shelf_location, $m->box_number ? __('box :n', ['n' => $m->box_number]) : null, $m->folder_number ? __('folder :n', ['n' => $m->folder_number]) : null])->filter()->join(' · ') }}</small>@endif
                @if($m->handling_instructions)<br><small>{{ __('Handling') }}: {{ $m->handling_instructions }}</small>@endif</p>
            @if($staff)
                <form method="post" action="{{ route('reading-room.requests.step', [$m, 'status']) }}" style="display: inline">@csrf
                    <select name="status" aria-label="{{ __('Next step') }}">@foreach(['retrieved', 'delivered', 'in-use', 'returned', 'unavailable', 'requested'] as $s)<option value="{{ $s }}">{{ \App\Models\Concept::firstWhere('iri', \App\Research\Retrieval::STATUSES.$s)?->label($lang) }}</option>@endforeach</select>
                    <input name="notes" size="24" aria-label="{{ __('Notes') }}" placeholder="{{ __('Notes') }}"> <button type="submit">{{ __('Move on') }}</button></form>
                @if($m->curatorial_approval_required && ! $m->curatorial_approved_at)<form method="post" action="{{ route('reading-room.requests.step', [$m, 'approve']) }}" style="display: inline">@csrf<button type="submit">{{ __('Approve as curator') }}</button></form>@endif
                @if($m->status->notation === 'returned' && ! $m->return_verified_at)<form method="post" action="{{ route('reading-room.requests.step', [$m, 'verify-return']) }}" style="display: inline">@csrf<button type="submit">{{ __('Check the return') }}</button></form>@endif
                <details><summary>{{ __('Handling') }}</summary>
                    <form method="post" action="{{ route('reading-room.requests.step', [$m, 'handle']) }}">@csrf
                        <p><label for="h-pr-{{ $m->id }}">{{ __('Priority') }}</label> <select id="h-pr-{{ $m->id }}" name="priority">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'request-priority/')->get() as $p)<option value="{{ $p->notation }}" @selected($m->priority->notation === $p->notation)>{{ $p->label($lang) }}</option>@endforeach</select>
                            <label for="h-q-{{ $m->id }}">{{ __('Queue') }}</label> <select id="h-q-{{ $m->id }}" name="queue_id"><option value="">-</option>@foreach(\App\Models\RequestQueue::where('institution_id', $booking->institution_id)->orderBy('position')->get() as $q)<option value="{{ $q->id }}" @selected($m->queue_id === $q->id)>{{ $q->name }}</option>@endforeach</select>
                            <label><input type="checkbox" name="curatorial_approval_required" value="1" @checked($m->curatorial_approval_required)> {{ __('A curator must approve it') }}</label></p>
                        <p><label for="h-loc-{{ $m->id }}">{{ __('Location') }}</label> <input id="h-loc-{{ $m->id }}" name="location_code" value="{{ $m->location_code }}"> <label for="h-shelf-{{ $m->id }}">{{ __('Shelf') }}</label> <input id="h-shelf-{{ $m->id }}" name="shelf_location" value="{{ $m->shelf_location }}" size="10">
                            <label for="h-box-{{ $m->id }}">{{ __('Box') }}</label> <input id="h-box-{{ $m->id }}" name="box_number" value="{{ $m->box_number }}" size="10"> <label for="h-folder-{{ $m->id }}">{{ __('Folder') }}</label> <input id="h-folder-{{ $m->id }}" name="folder_number" value="{{ $m->folder_number }}" size="8"></p>
                        <p><label for="h-ins-{{ $m->id }}">{{ __('Handling instructions') }}</label> <input id="h-ins-{{ $m->id }}" name="handling_instructions" value="{{ $m->handling_instructions }}" size="50">
                            <label for="h-due-{{ $m->id }}">{{ __('Due by') }}</label> <input id="h-due-{{ $m->id }}" type="date" name="sla_due_on" value="{{ $m->sla_due_on?->format('Y-m-d') }}"></p>
                        <button type="submit">{{ __('Save') }}</button></form>
                </details>
                <details><summary>{{ __('Custody') }}</summary>
                    @foreach($m->handoffs as $h)<p><small>{{ $h->created_at->format('Y-m-d H:i') }} · {{ $h->handoffType->label($lang) }}@if($h->condition) · {{ $h->condition->label($lang) }}@endif @if($h->condition_notes) · {{ $h->condition_notes }}@endif @if($h->signature_confirmed) · {{ __('signed') }}@endif</small></p>@endforeach
                    <form method="post" action="{{ route('reading-room.requests.step', [$m, 'handoff']) }}">@csrf
                        <label for="ho-t-{{ $m->id }}">{{ __('Hand-over') }}</label> <select id="ho-t-{{ $m->id }}" name="handoff_type">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'custody-handoff-type/')->get() as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
                        <label for="ho-c-{{ $m->id }}">{{ __('Condition') }}</label> <select id="ho-c-{{ $m->id }}" name="condition"><option value="">-</option>@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'container-condition/')->get() as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                        <input name="condition_notes" size="24" aria-label="{{ __('Condition notes') }}" placeholder="{{ __('Condition notes') }}">
                        <label><input type="checkbox" name="signature_confirmed" value="1"> {{ __('Signed for') }}</label> <button type="submit">{{ __('Record') }}</button></form>
                </details>
                <details><summary>{{ __('History') }}</summary>@foreach($m->statusChanges as $c)<p><small>{{ $c->created_at->format('Y-m-d H:i') }} · {{ $c->old_status ? __(':from to :to', ['from' => $c->old_status, 'to' => $c->new_status]) : $c->new_status }}@if($c->changedBy) · {{ $c->changedBy->name }}@endif @if($c->notes) · {{ $c->notes }}@endif</small></p>@endforeach</details>
            @endif
            @foreach($m->messages->filter(fn ($msg) => $staff || ! $msg->is_internal) as $msg)
                <p style="margin-left: 1rem"><small>{{ $msg->created_at->format('Y-m-d H:i') }} · {{ $msg->sender?->name }}@if($msg->is_internal) · <strong>{{ __('staff only') }}</strong>@endif</small><br>@if($msg->subject)<strong>{{ $msg->subject }}</strong><br>@endif<span style="white-space: pre-line">{{ $msg->body }}</span>
                    @if($msg->attachment)<br><a href="{{ route('research.files.show', $msg->attachment) }}">{{ $msg->attachment->original_name }}</a>@endif</p>
            @endforeach
            <details><summary>{{ __('Write about it') }}</summary>
                <form method="post" action="{{ route('research.requests.write', $m) }}" enctype="multipart/form-data">@csrf
                    <input name="subject" size="40" aria-label="{{ __('Subject') }}" placeholder="{{ __('Subject') }}"><br><textarea name="body" rows="2" cols="70" required aria-label="{{ __('Message') }}"></textarea><br>
                    <input type="file" name="attachment" aria-label="{{ __('Attachment') }}">@if($staff) <label><input type="checkbox" name="is_internal" value="1"> {{ __('Staff only') }}</label>@endif <button type="submit">{{ __('Send') }}</button></form>
            </details>
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if(in_array($state, ['pending', 'confirmed'], true) && ! $booking->checked_in_at)
        <form method="post" action="{{ route('research.bookings.records', $booking) }}">@csrf<label for="add-iri">{{ __('Ask for another record (its persistent IRI)') }}</label> <input id="add-iri" name="iri" size="50" required> <button type="submit">{{ __('Ask') }}</button></form>
    @endif

    <h2>{{ __('Equipment') }}</h2>
    @foreach($booking->equipmentBookings as $e)
        <p>{{ $e->equipment->name }} · {{ substr($e->start_time, 0, 5) }} - {{ substr($e->end_time, 0, 5) }} · {{ $e->status->label($lang) }}
            @if($staff && $e->status->notation === 'reserved')<form method="post" action="{{ route('reading-room.equipment-bookings.step', [$e, 'check-out']) }}" style="display: inline">@csrf<button type="submit">{{ __('Hand it over') }}</button></form>@endif
            @if($staff && $e->status->notation === 'in-use')<form method="post" action="{{ route('reading-room.equipment-bookings.step', [$e, 'return']) }}" style="display: inline">@csrf<select name="condition" aria-label="{{ __('Condition on return') }}">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'return-condition/')->get() as $c)<option value="{{ $c->notation }}" @selected($c->notation === 'good')>{{ $c->label($lang) }}</option>@endforeach</select> <input name="return_notes" aria-label="{{ __('Notes') }}" size="20"> <button type="submit">{{ __('Returned') }}</button></form>@endif
            @if($e->status->notation === 'reserved')<form method="post" action="{{ route('reading-room.equipment-bookings.step', [$e, 'cancel']) }}" style="display: inline">@csrf<button type="submit">{{ __('Cancel') }}</button></form>@endif</p>
    @endforeach
    @php($free = $booking->room->equipment->filter(fn ($e) => $e->is_available && ! in_array($e->condition->notation, ['needs-repair', 'out-of-service'], true)))
    @if($free->isNotEmpty() && in_array($state, ['pending', 'confirmed'], true) && ! $staff)
        <details><summary>{{ __('Book equipment for this visit') }}</summary>
            @foreach($free as $e)<form method="post" action="{{ route('research.equipment.book', $e) }}">@csrf<input type="hidden" name="booking_id" value="{{ $booking->id }}"><input type="hidden" name="booking_date" value="{{ $booking->booking_date->format('Y-m-d') }}">
                {{ $e->name }}@if($e->requires_training) <span class="meta">{{ __('needs training') }}</span>@endif · <input type="time" name="start_time" value="{{ substr($booking->start_time, 0, 5) }}" aria-label="{{ __('From') }}"> <input type="time" name="end_time" value="{{ substr($booking->end_time, 0, 5) }}" aria-label="{{ __('to') }}"> <button type="submit">{{ __('Book') }}</button></form>@endforeach
        </details>
    @endif
@endsection
