{{-- OpenRiC Workspace - ask for an account (Heratio's registration-register). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Ask for an account'))
@section('content')
    <h1>{{ __('Ask for an account') }}</h1>
    <p>{{ __('An administrator of :institution reads each request. Once it is approved you sign in with this email and the password you choose here.', ['institution' => $institution->name]) }}</p>
    <form method="post" action="{{ route('register') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">{{ __('Your name') }}</label>
        <input id="name" name="name" required maxlength="1024" autocomplete="name" value="{{ old('name') }}">
        <label for="email">{{ __('Email') }}</label>
        <input id="email" name="email" type="email" required maxlength="255" autocomplete="email" value="{{ old('email') }}">
        <label for="password">{{ __('Password (at least 12 characters)') }}</label>
        <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
        <label for="password_confirmation">{{ __('Confirm password') }}</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
        <label for="reason">{{ __('Why you would like an account (optional)') }}</label>
        <textarea id="reason" name="reason" rows="4" maxlength="2000">{{ old('reason') }}</textarea>
        <div style="position: absolute; left: -9999px" aria-hidden="true"><label for="website">{{ __('Leave this empty') }}</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <button type="submit">{{ __('Send the request') }}</button>
    </form>
    <p><a href="{{ route('sign-in') }}">{{ __('Log in') }}</a></p>
@endsection
