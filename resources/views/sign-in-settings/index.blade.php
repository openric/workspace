{{-- OpenRiC Workspace - the institution's sign-in settings: identity providers and the two-factor policy (roadmap phase 12b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Sign-in settings'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Sign-in settings') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <h2>{{ __('Two-factor sign-in') }}</h2>
    <form method="post" action="{{ route('sign-in-settings.policy') }}">@csrf
        <label for="policy">{{ __('Who must sign in with a second factor') }}</label>
        <select id="policy" name="policy">@foreach($policies as $p)<option value="{{ $p->notation }}" @selected($policy === $p->notation)>{{ $p->label($lang) }}</option>@endforeach</select>
        <label for="grace">{{ __('Days to set it up before nothing else can be done') }}</label><input id="grace" name="grace_days" value="{{ $institution->two_factor_grace_days }}" inputmode="numeric">
        <small>{{ __('Administrators are accounts that may manage the institution, its accounts or its roles. Signing in through an identity provider below uses that provider\'s own second factor.') }}</small>
        <button type="submit">{{ __('Save') }}</button></form>
    <h2>{{ __('Identity providers') }}</h2>
    <p>{{ __('Signing in with the institution\'s own accounts, by OpenID Connect: Microsoft Entra ID, Google Workspace, Keycloak and others. Register the Workspace with the provider, with this redirect address for each provider: :url', ['url' => url('/sign-in/provider/<id>/callback')]) }}</p>
    <p>{{ __('A sign-in that matches no account becomes a request for one, which an administrator approves under Users, Requests; its account then signs in with the provider.') }}</p>
    @foreach($providers as $p)
        <details><summary>{{ $p->label }} · <code>{{ $p->issuer_url }}</code>@unless($p->is_active) · <strong>{{ __('off') }}</strong>@endunless</summary>
            <p>{{ __('Redirect address') }}: <code>{{ route('sso.callback', $p) }}</code></p>
            <form method="post" action="{{ route('sign-in-settings.providers.update', $p) }}">@csrf @include('sign-in-settings._provider', ['p' => $p, 'x' => 'p'.$loop->index]) <button type="submit">{{ __('Save') }}</button></form></details>
    @endforeach
    <details @if($providers->isEmpty()) open @endif><summary>{{ __('Add an identity provider') }}</summary>
        <form method="post" action="{{ route('sign-in-settings.providers.store') }}">@csrf @include('sign-in-settings._provider', ['p' => null, 'x' => 'pn']) <button type="submit">{{ __('Add') }}</button></form></details>
@endsection
