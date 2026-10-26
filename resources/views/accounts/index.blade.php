{{-- OpenRiC Workspace - accounts list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Users'))
@section('content')
    <h1>{{ __('Users') }}</h1>
    @php($waiting = \App\Models\RegistrationRequest::where('institution_id', auth()->user()->institution_id)->whereNull('approved_at')->whereNull('rejected_at')->count())
    <p><a href="{{ route('registrations.index') }}">{{ __('Requests for an account') }}</a>@if($waiting) ({{ $waiting }} waiting)@endif</p>
    <p><a href="{{ route('accounts.create') }}">{{ __('New user') }}</a></p>
    <table>
        <thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Email') }}</th><th scope="col">{{ __('Roles') }}</th><th scope="col">{{ __('State') }}</th></tr></thead>
        <tbody>
        @foreach($users as $user)
            <tr>
                <td><a href="{{ route('accounts.show', $user) }}">{{ $user->name }}</a></td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->roles->map(fn ($r) => $r->name())->join(', ') ?: 'none of its own' }}</td>
                <td>{{ $user->isDisabled() ? 'Disabled' : 'Active' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
