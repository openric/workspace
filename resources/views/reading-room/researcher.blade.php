{{-- OpenRiC Workspace - a researcher, for staff (Heratio's viewResearcher with approve, verify, reject and suspend; roadmap phase 19b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $researcher->first_name.' '.$researcher->last_name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('reading-room.researchers') }}">{{ __('Researchers') }}</a></p>
    <h1>{{ $researcher->title }} {{ $researcher->first_name }} {{ $researcher->last_name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($researcher->photo)<p><img src="{{ route('research.files.show', $researcher->photo) }}" alt="{{ __('Photograph') }}" style="max-height: 140px"></p>@endif
    <dl>
        <dt>{{ __('Status') }}</dt><dd>{{ $researcher->status->label($lang) }}@if($researcher->rejection_reason) ({{ $researcher->rejection_reason }})@endif @if($researcher->approvedBy) · {{ __('approved by :who on :date', ['who' => $researcher->approvedBy->name, 'date' => $researcher->approved_at->format('Y-m-d')]) }}@endif</dd>
        @if($researcher->expires_on)<dt>{{ __('Until') }}</dt><dd>{{ $researcher->expires_on->format('Y-m-d') }}</dd>@endif
        @if($researcher->card_number)<dt>{{ __('Reader\'s card') }}</dt><dd>{{ $researcher->card_number }}</dd>@endif
        <dt>{{ __('Account') }}</dt><dd>{{ $researcher->user->name }} · {{ $researcher->user->email }}</dd>
        @if($researcher->phone)<dt>{{ __('Telephone') }}</dt><dd>{{ $researcher->phone }}</dd>@endif
        <dt>{{ __('Affiliation') }}</dt><dd>{{ $researcher->affiliation->label($lang) }}@if($researcher->institution) · {{ $researcher->institution }}@endif @if($researcher->department) · {{ $researcher->department }}@endif @if($researcher->position) · {{ $researcher->position }}@endif @if($researcher->student_id) · {{ __('student :n', ['n' => $researcher->student_id]) }}@endif</dd>
        @if($researcher->type)<dt>{{ __('Kind of researcher') }}</dt><dd>{{ $researcher->type->name }}</dd>@endif
        @if($researcher->research_interests)<dt>{{ __('Research interests') }}</dt><dd style="white-space: pre-line">{{ $researcher->research_interests }}</dd>@endif
        @if($researcher->current_project)<dt>{{ __('Current project') }}</dt><dd style="white-space: pre-line">{{ $researcher->current_project }}</dd>@endif
        @if($researcher->orcid_id || $researcher->researcher_id_wos || $researcher->scopus_id || $researcher->isni)<dt>{{ __('Identifiers') }}</dt><dd>{{ collect(['ORCID' => $researcher->orcid_id, 'WoS' => $researcher->researcher_id_wos, 'Scopus' => $researcher->scopus_id, 'ISNI' => $researcher->isni])->filter()->map(fn ($v, $k) => "$k $v")->join(' · ') }}</dd>@endif
        <dt>{{ __('Identity') }}</dt><dd>{{ $researcher->idType?->label($lang) ?? '-' }}@if($researcher->id_number) {{ $researcher->id_number }}@endif · {{ $researcher->id_verified ? __('verified on :date', ['date' => $researcher->id_verified_at?->format('Y-m-d')]) : __('not verified') }}</dd>
    </dl>
    <h2>{{ __('Decide') }}</h2>
    <form method="post" action="{{ route('reading-room.researchers.decide', [$researcher, 'approve']) }}">@csrf<label for="d-until">{{ __('Approve until') }}</label> <input id="d-until" type="date" name="expires_on" value="{{ now()->addMonths($researcher->type?->expiry_months ?? 12)->format('Y-m-d') }}"> <button type="submit">{{ __('Approve') }}</button></form>
    <form method="post" action="{{ route('reading-room.researchers.decide', [$researcher, 'reject']) }}">@csrf<label for="d-reject">{{ __('Reason') }}</label> <input id="d-reject" name="reason" size="50"> <button type="submit">{{ __('Reject') }}</button></form>
    <form method="post" action="{{ route('reading-room.researchers.decide', [$researcher, 'suspend']) }}">@csrf<label for="d-suspend">{{ __('Reason') }}</label> <input id="d-suspend" name="reason" size="50"> <button type="submit">{{ __('Suspend') }}</button></form>
    <h2>{{ __('Checks of identity and affiliation') }}</h2>
    @forelse($researcher->verifications as $v)<p>{{ $v->type->label($lang) }} · {{ $v->status->label($lang) }}@if($v->document_type) · {{ $v->document_type }}@endif @if($v->document_reference) {{ $v->document_reference }}@endif · {{ $v->verifiedBy?->name }} {{ $v->verified_at?->format('Y-m-d') }}
        @if($v->document) · <a href="{{ route('research.files.show', $v->document) }}">{{ $v->document->original_name }}</a>@endif @if($v->rejection_reason) · {{ $v->rejection_reason }}@endif @if($v->notes)<br><small>{{ $v->notes }}</small>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('Record a check') }}</summary>
        <form method="post" action="{{ route('reading-room.researchers.verify', $researcher) }}" enctype="multipart/form-data">@csrf
            <p><label for="v-type">{{ __('How') }}</label> <select id="v-type" name="type">@foreach($verificationTypes as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
                <label for="v-status">{{ __('Outcome') }}</label> <select id="v-status" name="status">@foreach($verificationStatuses as $t)<option value="{{ $t->notation }}" @selected($t->notation === 'verified')>{{ $t->label($lang) }}</option>@endforeach</select></p>
            <p><label for="v-doctype">{{ __('Document') }}</label> <input id="v-doctype" name="document_type" size="20"> <label for="v-ref">{{ __('Its reference') }}</label> <input id="v-ref" name="document_reference" size="20">
                <label for="v-file">{{ __('A copy') }}</label> <input id="v-file" type="file" name="document"> <label for="v-expires">{{ __('Valid until') }}</label> <input id="v-expires" type="date" name="expires_on"></p>
            <p><label for="v-reason">{{ __('Why it was not accepted') }}</label> <input id="v-reason" name="rejection_reason" size="40"> <label for="v-notes">{{ __('Notes') }}</label> <input id="v-notes" name="notes" size="40"></p>
            <button type="submit">{{ __('Record') }}</button></form>
    </details>
    <h2>{{ __('Bookings') }}</h2>
    @forelse($bookings as $b)<p><a href="{{ route('research.bookings.show', $b) }}">{{ $b->booking_date->format('Y-m-d') }}</a> · {{ $b->room->name }} · {{ $b->status->label($lang) }}</p>@empty<p>{{ __('None.') }}</p>@endforelse
@endsection
