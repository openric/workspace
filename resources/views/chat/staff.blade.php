{{-- OpenRiC Workspace - the catalogue's assistant for staff (roadmap phase 17f): what the account may read, and the help guides. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Ask the assistant'))
@section('content')
    <h1>{{ __('Ask the assistant') }}</h1>
    <p>{{ __('It answers from the records you may read, drafts too where you may see them, and from the Workspace\'s help guides.') }}</p>
    @include('chat._conversation')
@endsection
