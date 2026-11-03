{{-- OpenRiC Workspace - the second factor after the password (roadmap phase 12b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Two-factor sign-in'))
@section('content')
    <h1>{{ __('Two-factor sign-in') }}</h1>
    <form method="post" action="{{ route('two-factor.verify') }}" novalidate>
        @csrf
        @error('code')<p class="error" id="code-error" role="alert">{{ $message }}</p>@enderror
        @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
        <label for="code">{{ __('The six-digit code from your authenticator app, or a recovery code') }}</label>
        <input id="code" name="code" autocomplete="one-time-code" inputmode="numeric" required autofocus maxlength="20" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
        <button type="submit">{{ __('Sign in') }}</button>
    </form>
    <p><a href="{{ route('sign-in') }}">{{ __('Start again') }}</a></p>
@endsection
