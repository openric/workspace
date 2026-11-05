{{-- OpenRiC Workspace - after lodging a repatriation claim (roadmap phase 17d2), after Heratio's repatriation/lodge-thanks. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Claim received'))
@section('content')
    <h1>{{ __('Thank you - your claim has been received') }}</h1>
    <p>{{ __('A member of staff will review it and be in touch at the email you gave.') }}</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    <p><a href="{{ route('discover.home') }}">{{ __('Back to the catalogue') }}</a></p>
@endsection
