{{-- OpenRiC Workspace - a page the public saw, now deleted or merged: its IRI still answers, as deprecated (Governance profile 4, ADR 0013). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Withdrawn'))
@section('content')
    <h1>{{ $deprecation->name ?? __('Withdrawn') }}</h1>
    <p>{{ __('This description was withdrawn on :date.', ['date' => $deprecation->deprecated_at->format('j F Y')]) }} @if($deprecation->reason){{ $deprecation->reason }}@endif</p>
    @if($deprecation->replaced_by_iri)
        <p>{{ __('It is replaced by') }} <a href="{{ $deprecation->replaced_by_iri }}">{{ $deprecation->replaced_by_iri }}</a>.</p>
    @endif
    <p>{{ __('Its persistent identifier stays, so what cites it does not break:') }} <code>{{ $deprecation->iri }}</code></p>
@endsection
