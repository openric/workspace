{{-- OpenRiC Workspace - a repatriation claim's shared record link that no longer opens (roadmap phase 17d2), after Heratio's repatriation/shared-inactive. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Shared record link not active'))
@section('content')
    <h1>{{ __('This shared-record link is not active') }}</h1>
    <p>{{ __('The link you used is not valid, or it has been revoked or has expired. If you were given this link by a holding institution, please contact them to request a new one.') }}</p>
@endsection
