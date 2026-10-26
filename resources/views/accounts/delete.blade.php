{{-- OpenRiC Workspace - confirm deleting an account (Heratio's user confirmDelete). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Delete '.$account->name)
@section('content')
    <p><a href="{{ route('accounts.show', $account) }}">{{ $account->name }}</a></p>
    <h1>Delete {{ $account->name }}?</h1>
    <p>{{ __('Only an account that has never acted in the Workspace can be deleted: one that has signed in, imported or made assertions is kept, so the audit log still says who did what. Disable such an account instead.') }}</p>
    <p>{{ __('Its role memberships, its own permissions, its translation languages and its contact details go with it. This cannot be undone.') }}</p>
    <form method="post" action="{{ route('accounts.destroy', $account) }}">
        @csrf
        <button type="submit" style="background: var(--oric-danger)">{{ __('Delete the user') }}</button>
        <a href="{{ route('accounts.show', $account) }}" style="margin-left: 1rem">{{ __('Keep it') }}</a>
    </form>
@endsection
