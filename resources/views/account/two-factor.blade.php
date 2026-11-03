{{-- OpenRiC Workspace - an account's two-factor sign-in (Heratio's ahg-security-clearance two-factor setup; roadmap phase 12b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Two-factor sign-in'))
@section('content')
    <p><a href="{{ route('account.profile') }}">{{ __('Back to your profile') }}</a></p>
    <h1>{{ __('Two-factor sign-in') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('After your password you give a six-digit code from an authenticator app on your phone, such as Microsoft Authenticator, Google Authenticator or FreeOTP. Signing in through your institution\'s own sign-in uses that sign-in\'s second factor instead.') }}</p>
    @if($required)<p><strong>{{ __('Your institution requires it for your account.') }}</strong></p>@endif
    @if($codes)
        <h2>{{ __('Recovery codes') }}</h2>
        <p>{{ __('Each lets you in once without your phone. They are shown only now: print them or keep them in a password manager.') }}</p>
        <ul style="font-family: monospace; columns: 2">@foreach($codes as $code)<li>{{ chunk_split($code, 5, ' ') }}</li>@endforeach</ul>
    @endif
    @if($enabled)
        <p role="status">{{ __('It is on.') }} {{ trans_choice(':count recovery code left.|:count recovery codes left.', $left, ['count' => $left]) }}</p>
        <form method="post" action="{{ route('two-factor.regenerate') }}">@csrf
            <label for="regen-code">{{ __('A current code, for new recovery codes') }}</label><input id="regen-code" name="code" autocomplete="one-time-code" inputmode="numeric" maxlength="20" required>
            <button type="submit">{{ __('Make new recovery codes') }}</button></form>
        <form method="post" action="{{ route('two-factor.disable') }}">@csrf
            <label for="off-code">{{ __('A current code, to turn it off') }}</label><input id="off-code" name="code" autocomplete="one-time-code" inputmode="numeric" maxlength="20" required>
            <button type="submit">{{ __('Turn two-factor sign-in off') }}</button></form>
    @elseif($setup)
        <h2>{{ __('Scan this with your authenticator app') }}</h2>
        <img src="{{ $setup['qr'] }}" alt="{{ __('QR code for your authenticator app') }}" width="200" height="200">
        <p>{{ __('Or type this key into the app') }}: <code style="word-break: break-all">{{ chunk_split($setup['secret'], 4, ' ') }}</code></p>
        <form method="post" action="{{ route('two-factor.confirm') }}">@csrf
            <label for="confirm-code">{{ __('The code the app now shows') }}</label><input id="confirm-code" name="code" autocomplete="one-time-code" inputmode="numeric" maxlength="6" required autofocus>
            <button type="submit">{{ __('Turn it on') }}</button></form>
    @else
        <form method="post" action="{{ route('two-factor.begin') }}">@csrf<button type="submit">{{ __('Set up two-factor sign-in') }}</button></form>
    @endif
@endsection
