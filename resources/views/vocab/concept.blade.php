{{-- OpenRiC Workspace - a concept page. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $concept->label(\App\Support\Vocab::languages()) ?? $concept->notation)
@section('content')
    <h1>{{ $concept->label(\App\Support\Vocab::languages()) ?? $concept->notation }}</h1>
    <p><strong>Concept IRI:</strong> <code>{{ $concept->iri }}</code><br>
       <strong>In scheme:</strong> <a href="{{ $concept->vocabulary->iri }}">{{ $concept->vocabulary->title }}</a>
       @if($concept->broader)<br><strong>Broader:</strong> <a href="{{ $concept->broader->iri }}">{{ $concept->broader->notation }}</a>@endif</p>
    @if($concept->deprecated_at)
        <p>Deprecated.@if($concept->replacedBy) Use <a href="{{ $concept->replacedBy->iri }}">{{ $concept->replacedBy->notation }}</a> instead.@endif</p>
    @endif
    @foreach($concept->labels as $label)
        <p lang="{{ $label->language }}">{{ $label->definition }}</p>
        @if($label->scope_note)<p lang="{{ $label->language }}">{{ $label->scope_note }}</p>@endif
    @endforeach
@endsection
