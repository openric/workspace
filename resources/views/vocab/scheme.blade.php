{{-- OpenRiC Workspace - a vocabulary page. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $vocabulary->title)
@section('content')
    <h1>{{ $vocabulary->title }}</h1>
    <p><strong>Scheme IRI:</strong> <code>{{ $vocabulary->iri }}</code><br>
       <strong>Machine-readable:</strong> <a href="{{ rtrim($vocabulary->iri, '/') }}.ttl">Turtle</a>, or this IRI with <code>Accept: text/turtle</code></p>
    <table>
        <thead><tr><th scope="col">Concept</th><th scope="col">Label</th><th scope="col">Definition</th></tr></thead>
        <tbody>
        @foreach($concepts as $concept)
            <tr>
                <td><a href="{{ $concept->iri }}"><code>{{ $concept->notation }}</code></a>@if($concept->deprecated_at) (deprecated)@endif</td>
                <td>{{ $concept->label(\App\Support\Vocab::languages()) }}</td>
                <td>{{ $concept->labels->firstWhere('language', 'en')?->definition }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
