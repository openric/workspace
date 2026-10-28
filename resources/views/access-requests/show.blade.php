{{-- OpenRiC Workspace - one access request, and its review (roadmap phase 7; after Heratio's ahg-access-request view). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Access request'))
@section('content')
    @php
        $lang = \App\Support\Vocab::languages();
        $r = $accessRequest;
        $title = $r->target();
    @endphp
    <p><a href="{{ $r->user_id === auth()->id() ? route('access-requests.mine') : route('access-requests.index') }}">{{ $r->user_id === auth()->id() ? __('Back to My access requests') : __('Back to Access requests') }}</a></p>
    <h1>{{ $r->subject ?? __('Access to :file', ['file' => $title]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>@if($r->instantiation)<strong>{{ __('File') }}:</strong> <a href="{{ route('instantiations.show', $r->instantiation) }}">{{ $title }}</a> {{ __('of') }} <a href="{{ route('records.show', $r->record) }}">{{ $r->record->described('title', [app()->getLocale(), 'en']) }}</a>
        @else<strong>{{ __('What do you need access to?') }}</strong> {{ $r->covers->label($lang) }}@if($r->record): <a href="{{ route('records.show', $r->record) }}">{{ $title }}</a>@endif @endif
        @if($r->requestType)<br><strong>{{ __('Request type') }}:</strong> {{ $r->requestType->label($lang) }}@endif
        @if($r->accessType)<br><strong>{{ __('Access type') }}:</strong> {{ $r->accessType->label($lang) }}@endif
        <br><strong>{{ __('Requester') }}:</strong> {{ $r->user->name }} &lt;{{ $r->user->email }}&gt;
        <br><strong>{{ __('Status') }}:</strong> {{ $r->status->label($lang) }}@if($r->grantsAccess()), {{ __('access until :date', ['date' => $r->expires_at->format('Y-m-d H:i')]) }}@endif
        <br><strong>{{ __('Urgency') }}:</strong> {{ $r->urgency->label($lang) }}
        <br><strong>{{ __('Received') }}:</strong> {{ $r->created_at->format('Y-m-d H:i') }}
        @if($r->reviewer)<br><strong>{{ __('Reviewed by') }}:</strong> {{ $r->reviewer->name }}, {{ $r->reviewed_at->format('Y-m-d H:i') }}@endif</p>
    <h2>{{ $r->subject ? __('Description') : __('Reason') }}</h2>
    <p style="white-space: pre-line">{{ $r->reason }}</p>
    @if($r->justification)<h2>{{ __('Justification') }}</h2><p style="white-space: pre-line">{{ $r->justification }}</p>@endif
    @if($r->review_notes)<h2>{{ __('Reviewer\'s note') }}</h2><p style="white-space: pre-line">{{ $r->review_notes }}</p>@endif

    @if($r->user_id === auth()->id() && ($r->hasStatus('pending') || $r->hasStatus('approved')))
        <form method="post" action="{{ route('access-requests.cancel', $r) }}">@csrf<button type="submit">{{ $r->hasStatus('pending') ? __('Cancel the request') : __('End my access now') }}</button></form>
    @endif
    @if($mayReview && $r->hasStatus('pending'))
        <h2>{{ __('Review') }}</h2>
        <form method="post" action="{{ route('access-requests.approve', $r) }}">
            @csrf
            <label for="days">{{ __('Days of access') }}</label>
            <input id="days" name="days" type="number" min="1" max="{{ $maxDays }}" required value="{{ old('days', $days) }}">
            <label for="approve-notes">{{ __('Note to the requester (optional)') }}</label>
            <textarea id="approve-notes" name="notes" rows="3" maxlength="4000"></textarea>
            <button type="submit">{{ __('Approve') }}</button>
        </form>
        <form method="post" action="{{ route('access-requests.deny', $r) }}">
            @csrf
            <label for="deny-notes">{{ __('Why it is refused') }}</label>
            <textarea id="deny-notes" name="notes" rows="3" maxlength="4000" required></textarea>
            <button type="submit">{{ __('Refuse') }}</button>
        </form>
    @endif
@endsection
