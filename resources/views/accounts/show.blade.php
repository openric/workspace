{{-- OpenRiC Workspace - one account. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $account->name)
@section('content')
    <p><a href="{{ route('accounts.index') }}">All accounts</a></p>
    <h1>{{ $account->name }}</h1>
    <p>{{ $account->email }}. {{ $account->isDisabled() ? 'Disabled.' : 'Active.' }}</p>
    <p><a href="{{ route('accounts.edit', $account) }}">Edit account and contact details</a> · <a href="{{ route('accounts.access', $account) }}">What it may do</a>@unless($account->is(auth()->user())) · <a href="{{ route('accounts.delete', $account) }}">Delete account</a>@endunless</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if(session('password_link'))
        <div role="status">
            <p><strong>One-time link to set the password</strong>, valid for {{ intdiv(config('auth.passwords.users.expire'), 60) }} hours. Send it to {{ $account->email }} yourself; it is shown only now.</p>
            <p><code style="word-break: break-all">{{ session('password_link') }}</code></p>
        </div>
    @endif

    <h2>Roles</h2>
    <form method="post" action="{{ route('accounts.roles', $account) }}">
        @csrf
        @include('accounts._roles', ['roles' => $roles, 'selected' => $account->roles->pluck('id')->all()])
        <button type="submit">Save roles</button>
    </form>

    @can(\App\Support\Vocab::permission('manage-roles'))
        <h2>Permissions of this account</h2>
        <p>Permissions set for this account alone come before those of its roles.</p>
        <ul>
            @foreach($kinds as $kind)<li><a href="{{ route('accounts.permissions', [$account, $kind->notation]) }}">{{ $kind->label(['en', 'und']) }}</a></li>@endforeach
        </ul>
    @endcan

    <h2 id="api-keys">API keys</h2>
    @include('accounts._api-keys', ['storeUrl' => route('accounts.api-keys.store', $account), 'revokeUrl' => fn ($key) => route('accounts.api-keys.revoke', [$account, $key])])

    <h2>Access</h2>
    <form method="post" action="{{ route('accounts.password-link', $account) }}">
        @csrf
        <button type="submit">New password link</button>
    </form>
    @if($account->isDisabled())
        <form method="post" action="{{ route('accounts.enable', $account) }}">@csrf<button type="submit">Enable account</button></form>
    @else
        <form method="post" action="{{ route('accounts.disable', $account) }}">@csrf<button type="submit">Disable account</button></form>
    @endif
@endsection
