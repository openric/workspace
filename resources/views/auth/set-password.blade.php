{{-- OpenRiC Workspace - set a password from a link. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Set your password'))
@section('content')
    <h1>{{ __('Set your password') }}</h1>
    <form method="post" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <p>{{ __('For :email. At least 12 characters.', ['email' => $email]) }}</p>
        <label for="password">{{ __('New password') }}</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>
        <label for="password_confirmation">{{ __('New password again') }}</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button type="submit">{{ __('Set password') }}</button>
    </form>
@endsection
