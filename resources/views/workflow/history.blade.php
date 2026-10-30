{{-- OpenRiC Workspace - the workflow history of the institution (Heratio's workflow history). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Workflow history'))
@section('content')
    <h1>{{ __('Workflow history') }}</h1>
    @include('workflow._nav')
    @include('workflow._events', ['events' => $events])
@endsection
