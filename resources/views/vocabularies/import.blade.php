{{-- OpenRiC Workspace - import SKOS into a vocabulary. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Import SKOS'))
@section('content')
    <p><a href="{{ route('vocabularies.show', $vocabulary) }}">{{ __('Back to the vocabulary') }}</a></p>
    <h1>{{ __('Import SKOS into :name', ['name' => $vocabulary->title]) }}</h1>
    <p>{{ __('Every skos:Concept in the file is added with this institution\'s IRI, its labels, alternative and hidden labels, notes, code, broader and related concepts and matches. The IRI it had becomes an exact match, so importing the same file again updates the concepts instead of adding them twice.') }}</p>
    <form method="post" action="{{ route('vocabularies.import', $vocabulary) }}" enctype="multipart/form-data">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="file">{{ __('SKOS file (at most 20 MB)') }}</label>
        <input id="file" name="file" type="file" required accept=".ttl,.rdf,.xml,.nt">
        <label for="format">{{ __('Format') }}</label>
        <select id="format" name="format" style="font: inherit; padding: .5rem">
            <option value="turtle">{{ __('Turtle') }}</option>
            <option value="rdfxml">RDF/XML</option>
            <option value="ntriples">{{ __('N-Triples') }}</option>
        </select>
        <button type="submit">{{ __('Import') }}</button>
    </form>
@endsection
