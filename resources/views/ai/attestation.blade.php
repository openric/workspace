{{-- OpenRiC Workspace - a reviewer's attestation that they know how to review AI output (roadmap phase 17f, after Heratio's ai_operator_attestation; EU AI Act article 14 on automation bias). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Reviewing AI output'))
@section('content')
    <h1>{{ __('Reviewing AI output') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('What a model proposes is a suggestion, never a fact. Before you accept it:') }}</p>
    <ul>
        <li>{{ __('Check it against the record itself: the page, the photograph, the description.') }}</li>
        <li>{{ __('Do not accept a proposal because it looks right or because most are right: models are often confidently wrong, and the more you accept the less you look (automation bias).') }}</li>
        <li>{{ __('Reject what you cannot check. A rejected proposal costs nothing; a wrong value in a published record misleads every reader.') }}</li>
        <li>{{ __('Personal information a model finds in a record is still personal information: the record\'s access and privacy rules apply to it.') }}</li>
        <li>{{ __('If a model keeps getting something wrong, tell whoever manages the institution: they may record it as an incident or halt the system.') }}</li>
    </ul>
    @if($current)
        <p>{{ __('You attested on :date; it holds until :until.', ['date' => $current->attested_at->toDateString(), 'until' => $current->expires_at->toDateString()]) }}</p>
    @else
        <form method="post" action="{{ route('ai.attest') }}">@csrf
            <p><label><input type="checkbox" name="read" value="1" required> {{ __('I have read this and will review AI output accordingly.') }}</label></p>
            <button type="submit">{{ __('Attest') }}</button></form>
    @endif
@endsection
