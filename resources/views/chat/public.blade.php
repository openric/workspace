{{-- OpenRiC Workspace - the catalogue's assistant in the public catalogue (roadmap phase 17f). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Ask the catalogue'))
@section('content')
    <h1>{{ __('Ask the catalogue') }}</h1>
    @include('chat._conversation')
@endsection
