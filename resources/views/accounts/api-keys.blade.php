{{-- OpenRiC Workspace - the signed-in account's own API keys (Heratio's /api/keys, ADR 0007). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs what ApiKeyController::managerData gives. --}}
@extends('layouts.app')
@section('title', __('Your API keys'))
@section('content')
    <p><a href="{{ route('account.profile') }}">{{ __('Profile') }}</a></p>
    <h1>{{ __('Your API keys') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @include('accounts._api-keys', ['storeUrl' => route('account.api-keys.store'), 'revokeUrl' => fn ($key) => route('account.api-keys.revoke', $key)])
@endsection
