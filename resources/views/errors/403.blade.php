{{-- OpenRiC Workspace - access denied. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Access denied'))
@section('content')
    <h1>{{ __('Access denied') }}</h1>
    <p>{{ __('Your roles do not allow this. An administrator can give you a role that does, or set a permission for your account under Roles and permissions.') }}</p>
    <p><a href="{{ auth()->check() ? route('home') : route('sign-in') }}">{{ auth()->check() ? 'Back to Home' : 'Sign in' }}</a></p>
@endsection
