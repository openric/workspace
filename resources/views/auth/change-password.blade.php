{{-- OpenRiC Workspace - change your own password. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Change your password')
@section('content')
    <h1>Change your password</h1>
    <form method="post" action="{{ route('password.update') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <label for="password">New password (at least 12 characters)</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>
        <label for="password_confirmation">New password again</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button type="submit">Change password</button>
    </form>
@endsection
