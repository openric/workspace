{{-- OpenRiC Workspace - a share link that cannot be used (roadmap phase 19a). Needs $refusal. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Share link'))
@section('content')
    <h1>{{ __('This link cannot be used') }}</h1>
    <p>{{ match ($refusal) { 'denied-expired' => __('This share link has expired.'), 'denied-revoked' => __('This share link has been revoked.'), 'denied-quota' => __('This share link has been used as often as it may be.'), default => __('There is no such share link.') } }}</p>
    <p>{{ __('Ask whoever sent it to you for a new one.') }}</p>
@endsection
