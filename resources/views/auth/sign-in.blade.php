{{-- OpenRiC Workspace - sign-in form. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Sign in'))
@section('content')
    <h1>{{ __('Sign in') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('sign-in') }}" novalidate>
        @csrf
        @error('email')<p class="error" id="sign-in-error" role="alert">{{ $message }}</p>@enderror
        <label for="email">{{ __('Email') }}</label>
        <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}"
               @error('email') aria-invalid="true" aria-describedby="sign-in-error" @enderror>
        <label for="password">{{ __('Password') }}</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">{{ __('Sign in') }}</button>
    </form>
    @if(\App\Support\HostInstitution::for(request())?->registration_open)<p>{{ __('No account yet?') }} <a href="{{ route('register') }}">{{ __('Ask for one') }}</a>.</p>@endif
@endsection
