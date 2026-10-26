{{-- OpenRiC Workspace - new role. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('New role'))
@section('content')
    <p><a href="{{ route('roles.index') }}">{{ __('All roles') }}</a></p>
    <h1>{{ __('New role') }}</h1>
    <form method="post" action="{{ route('roles.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        @include('roles._profile', ['role' => null, 'language' => 'en'])
        <button type="submit">{{ __('Create role') }}</button>
    </form>
@endsection
