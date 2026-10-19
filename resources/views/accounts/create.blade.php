{{-- OpenRiC Workspace - new account. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'New account')
@section('content')
    <h1>New account</h1>
    <p>The new account gets a one-time link to set its own password. Pass the link on to its owner; nobody else ever sees the password.</p>
    <form method="post" action="{{ route('accounts.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">Name</label>
        <input id="name" name="name" required value="{{ old('name') }}">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required value="{{ old('email') }}">
        @include('accounts._roles', ['roles' => $roles, 'selected' => old('roles', [])])
        <button type="submit">Create account</button>
    </form>
@endsection
