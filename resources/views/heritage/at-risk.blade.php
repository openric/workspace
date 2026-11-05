{{-- OpenRiC Workspace - the capture-priority worklist and every flag (roadmap phase 17d3), after Heratio's endangered/worklist (ahg-semantic-search), with the institution's choices. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Heritage at risk'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Heritage at risk') }}</h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('A description is flagged from its own page.') }}@if($manages) <a href="{{ route('heritage.inbound') }}">{{ __('Pushed at-risk flags') }}</a> ({{ $pending }})@endif</p>
    <h2>{{ __('Capture-priority worklist') }}</h2>
    <p class="meta">{{ __('Priority score combines the urgency band with a small bonus when no durable digital surrogate exists yet. Captured items leave the worklist; the public at-risk register shows published items only.') }}</p>
    @if($worklist->isEmpty())<p>{{ __('Nothing is flagged for priority capture') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Priority score') }}</th><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Risk') }}</th><th scope="col">{{ __('Urgency') }}</th><th scope="col">{{ __('Capture status') }}</th><th scope="col">{{ __('Reason') }}</th></tr></thead>
        <tbody>@foreach($worklist as $i)<tr><td>{{ \App\Heritage\Endangered::score($i->urgency->notation, $i->risk->notation, $i->captureStatus->notation) }}</td>
            <td><a href="{{ route('records.show', $i->record) }}">{{ \App\Relations\EntityKinds::label($i->record, $lang) }}</a></td><td>{{ $i->risk->label($lang) }}</td><td>{{ $i->urgency->label($lang) }}</td>
            <td><form method="post" action="{{ route('heritage.capture-status', $i) }}">@csrf<select name="capture_status" aria-label="{{ __('Capture status') }}">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($i->capture_status_id === $s->id)>{{ $s->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Set') }}</button></form></td>
            <td>{{ \Illuminate\Support\Str::limit((string) $i->reason, 120) }}</td></tr>@endforeach</tbody></table>
    @endif
    <h2>{{ __('Items flagged at risk') }}</h2>
    <p>{{ __('Filter by capture status:') }} @if($status === null)<strong>{{ __('All') }}</strong>@else<a href="{{ route('heritage.at-risk') }}">{{ __('All') }}</a>@endif
        @foreach($statuses as $s) · @if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('heritage.at-risk', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif ({{ $counts[$s->notation] ?? 0 }})@endforeach</p>
    <ul>@forelse($flags as $i)<li><a href="{{ route('records.show', $i->record) }}">{{ \App\Relations\EntityKinds::label($i->record, $lang) }}</a> · {{ $i->risk->label($lang) }} · {{ $i->urgency->label($lang) }} · {{ $i->captureStatus->label($lang) }}</li>@empty<li>{{ __('No items with this capture status') }}</li>@endforelse</ul>
    @if($manages)
    <h2>{{ __('Settings') }}</h2>
    <form method="post" action="{{ route('heritage.settings') }}">@csrf
        <p><label><input type="checkbox" name="public_register" value="1" @checked($settings->public_register)> {{ __('Show the register of heritage at risk in the public catalogue, with the network board of federation peers') }}</label></p>
        <p><label><input type="checkbox" name="share_with_peers" value="1" @checked($settings->share_with_peers)> {{ __('Let federation peers read the published register, signed') }}</label></p>
        <p><label><input type="checkbox" name="accept_pushes" value="1" @checked($settings->accept_pushes)> {{ __('Let federation peers push at-risk flags here, for review before any is shown') }}</label></p>
        <button type="submit">{{ __('Save') }}</button>
    </form>
    @endif
@endsection
