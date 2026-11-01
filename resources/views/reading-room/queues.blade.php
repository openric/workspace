{{-- OpenRiC Workspace - retrieval queues (Heratio's retrievalQueue; roadmap phase 19b): the requests of a queue, call slips to print, and the queues themselves. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Retrieval queues'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Retrieval queues') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>@forelse($queues as $q)@if($queue?->id === $q->id)<strong>{{ $q->name }}</strong>@else<a href="{{ route('reading-room.queues', ['queue' => $q->id]) }}">{{ $q->name }}</a>@endif @if(! $loop->last) · @endif @empty{{ __('No queue yet.') }}@endforelse</p>
    @if($queue)
        <p class="meta">{{ $queue->queueType->label($lang) }}@if($queue->description) · {{ $queue->description }}@endif</p>
        @if($requests->isEmpty())<p>{{ __('None.') }}</p>@else
        <form method="get" action="{{ route('reading-room.slips') }}" target="_blank">
        <table><thead><tr><th scope="col">{{ __('Choose') }}</th><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Where it is kept') }}</th><th scope="col">{{ __('For') }}</th><th scope="col">{{ __('Run') }}</th><th scope="col">{{ __('Priority') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>@foreach($requests as $r)<tr><td><input type="checkbox" name="ids[]" value="{{ $r->id }}" checked aria-label="{{ __('Choose :title', ['title' => \App\Relations\EntityKinds::label($r->record, $lang)]) }}"></td>
                <td><a href="{{ route('research.bookings.show', $r->booking) }}">{{ \App\Relations\EntityKinds::label($r->record, $lang) }}</a>@if($code = $r->record->referenceCode()) <span class="meta">{{ $code }}</span>@endif</td>
                <td>{{ collect([$r->location_code, $r->shelf_location, $r->box_number])->filter()->join(' · ') }}</td><td>{{ $r->booking->booking_date->format('Y-m-d') }} {{ substr($r->booking->start_time, 0, 5) }} · {{ $r->booking->room->name }}</td>
                <td>{{ $r->retrieval_scheduled_for?->format('H:i') }}</td><td>{{ $r->priority->label($lang) }}</td><td>{{ $r->status->label($lang) }}@if($r->call_slip_printed_at) · {{ __('slip printed') }}@endif</td></tr>@endforeach</tbody></table>
            <button type="submit">{{ __('Print call slips') }}</button></form>
        @endif
    @endif
    <h2>{{ __('Queues') }}</h2>
    @foreach($queues as $q)<details><summary>{{ $q->name }}</summary><form method="post" action="{{ route('reading-room.queues.update', $q) }}">@csrf @include('reading-room._queue-fields', ['q' => $q, 'p' => 'q'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>@endforeach
    <details><summary>{{ __('Add a queue') }}</summary><form method="post" action="{{ route('reading-room.queues.store') }}">@csrf @include('reading-room._queue-fields', ['q' => null, 'p' => 'q-new'])<button type="submit">{{ __('Add') }}</button></form></details>
@endsection
