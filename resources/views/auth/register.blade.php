{{-- OpenRiC Workspace - ask for an account (Heratio's registration-register). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Ask for an account')
@section('content')
    <h1>Ask for an account</h1>
    <p>An administrator of {{ $institution->name }} reads each request. Once it is approved you sign in with this email and the password you choose here.</p>
    <form method="post" action="{{ route('register') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">Your name</label>
        <input id="name" name="name" required maxlength="1024" autocomplete="name" value="{{ old('name') }}">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required maxlength="255" autocomplete="email" value="{{ old('email') }}">
        <label for="password">Password (at least 12 characters)</label>
        <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
        <label for="password_confirmation">The password again</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
        <label for="reason">Why you would like an account (optional)</label>
        <textarea id="reason" name="reason" rows="4" maxlength="2000">{{ old('reason') }}</textarea>
        <div style="position: absolute; left: -9999px" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <button type="submit">Send the request</button>
    </form>
    <p><a href="{{ route('sign-in') }}">Sign in</a></p>
@endsection
