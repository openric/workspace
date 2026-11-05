{{-- OpenRiC Workspace - lodge a repatriation claim about a published description (roadmap phase 17d2), after Heratio's repatriation/lodge (ahg-semantic-search). Needs $unit, $disclaimer, $renderedAt. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Lodge a repatriation claim'))
@section('content')
    <h1>{{ __('Lodge a repatriation claim') }}</h1>
    <p>{{ __('You are lodging a claim about:') }} <a href="{{ route('discover.record', $unit->id) }}">{{ $view->label($unit) }}</a></p>
    <p>{{ __('Tell us who you are and the grounds for your claim. A member of staff will review it and be in touch. Fields marked * are required.') }}</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('discover.repatriation.submit', $unit->id) }}">@csrf
        <input type="hidden" name="rendered_at" value="{{ $renderedAt }}">
        {{-- People leave this empty; robots fill it. --}}
        <div style="position: absolute; left: -10000px" aria-hidden="true"><label for="rl-website">Website</label><input id="rl-website" name="website" tabindex="-1" autocomplete="off"></div>
        <p><label for="rl-community">{{ __('Claimant community or nation') }} *</label><br><input id="rl-community" name="claimant_community" required maxlength="512" size="60" value="{{ old('claimant_community') }}"></p>
        <p><label for="rl-name">{{ __('Your name (optional)') }}</label> <input id="rl-name" name="claimant_name" maxlength="255" value="{{ old('claimant_name') }}">
            <label for="rl-email">{{ __('Contact email') }} *</label> <input id="rl-email" name="contact_email" type="email" required maxlength="255" value="{{ old('contact_email') }}"></p>
        <p class="meta">{{ __('We use this only to confirm receipt and update you on the claim.') }}</p>
        <p><label for="rl-origin">{{ __('Place of origin (optional)') }}</label> <input id="rl-origin" name="origin_place" maxlength="512" size="50" value="{{ old('origin_place', $trace ? $trace['origin']['value'] : null) }}"></p>
        <p><label for="rl-evidence">{{ __('Grounds for the claim and any evidence (optional)') }}</label><br><textarea id="rl-evidence" name="evidence_summary" rows="6" cols="70">{{ old('evidence_summary') }}</textarea></p>
        <p><label><input type="checkbox" name="consent" value="1" required @checked(old('consent'))> {{ __('I understand this records a documented request to open a dialogue and is not a legal determination, and I consent to staff contacting me about it.') }} *</label></p>
        <button type="submit">{{ __('Lodge claim') }}</button>
    </form>
@endsection
