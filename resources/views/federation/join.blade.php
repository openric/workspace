{{-- OpenRiC Workspace - ask to join an institution's federation network (Heratio's federation join form; roadmap phase 16c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Join the network'))
@section('content')
    <h1>{{ __('Join the network of :institution', ['institution' => $institution->name]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('An archive with its own public catalogue can ask to be searched with :institution\'s. Once the request is approved, the two catalogues search each other, each giving only what it publishes.', ['institution' => $institution->name]) }}</p>
    <form method="post" action="{{ route('federation.join.submit') }}">@csrf
        <label for="j-name">{{ __('Institution') }}</label><input id="j-name" name="institution_name" value="{{ old('institution_name') }}" required>
        <label for="j-contact">{{ __('Your name') }}</label><input id="j-contact" name="contact_name" value="{{ old('contact_name') }}" required>
        <label for="j-email">{{ __('Your e-mail address') }}</label><input id="j-email" name="contact_email" type="email" value="{{ old('contact_email') }}" required>
        <label for="j-url">{{ __('Your public catalogue\'s address') }}</label><input id="j-url" name="base_url" type="url" value="{{ old('base_url') }}" required placeholder="https://archives.example.org">
        <label for="j-share">{{ __('What you would share') }}</label><textarea id="j-share" name="what_they_share" rows="3">{{ old('what_they_share') }}</textarea>
        <label for="j-notes">{{ __('Anything else') }}</label><textarea id="j-notes" name="notes" rows="2">{{ old('notes') }}</textarea>
        <div style="position: absolute; left: -10000px" aria-hidden="true"><label for="j-website">Website</label><input id="j-website" name="website" tabindex="-1" autocomplete="off"></div>
        <button type="submit">{{ __('Ask to join') }}</button></form>
@endsection
