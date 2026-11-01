{{-- OpenRiC Workspace - one piece of feedback (roadmap phase 19a), after Heratio's feedback view and edit. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $entry->subject)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('feedback.index') }}">{{ __('Feedback received') }}</a></p>
    <h1>{{ $entry->subject }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <dl>
        <dt>{{ __('Type') }}</dt><dd>{{ $entry->type->label($lang) }}</dd>
        @if($entry->record)<dt>{{ __('Record') }}</dt><dd><a href="{{ route('records.show', $entry->record) }}">{{ \App\Relations\EntityKinds::label($entry->record, $lang) }}</a></dd>@endif
        <dt>{{ __('Remarks') }}</dt><dd style="white-space: pre-line">{{ $entry->remarks }}</dd>
        <dt>{{ __('From') }}</dt><dd>{{ $entry->name }} {{ $entry->surname }} · <a href="mailto:{{ $entry->email }}">{{ $entry->email }}</a>@if($entry->phone) · {{ $entry->phone }}@endif</dd>
        @if($entry->relationship)<dt>{{ __('Relationship to the record') }}</dt><dd>{{ $entry->relationship }}</dd>@endif
        <dt>{{ __('Received') }}</dt><dd>{{ $entry->created_at->format('Y-m-d H:i') }}</dd>
        @if($entry->completed_at)<dt>{{ __('Completed') }}</dt><dd>{{ $entry->completed_at->format('Y-m-d') }}@if($entry->completedBy) {{ __('by :who', ['who' => $entry->completedBy->name]) }}@endif</dd>@endif
    </dl>
    <form method="post" action="{{ route('feedback.update', $entry) }}">@csrf
        <p><label for="fb-status">{{ __('Status') }}</label> <select id="fb-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($entry->status->notation === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select></p>
        <p><label for="fb-notes">{{ __('Staff notes') }}</label><br><textarea id="fb-notes" name="staff_notes" rows="4" cols="70">{{ $entry->staff_notes }}</textarea></p>
        <button type="submit">{{ __('Save') }}</button></form>
    <form method="post" action="{{ route('feedback.destroy', $entry) }}" style="margin-top: 1rem">@csrf<button type="submit">{{ __('Delete') }}</button></form>
@endsection
