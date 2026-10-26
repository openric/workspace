{{-- OpenRiC Workspace - the identifiers of a described entity, as rico:Identifier entities (ADR 0006). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs: $subject (HasIdentifiers), $kind (URL segment), $canUpdate. The local identifier is also set on the description form. --}}
@php($identifierTypes = \App\Models\Concept::choices(\App\Support\Vocab::IDENTIFIER_TYPES)->get())
<h2>Identifiers @include('description._ric', ['term' => 'rico:Identifier'])</h2>
@forelse($subject->identifiers as $identifier)
    @php($summary = $identifier->identifierType->label(\App\Support\Vocab::languages()).': '.$identifier->value)
    @unless($canUpdate)
        <p>{{ $summary }}</p>
        @continue
    @endunless
    <details>
        <summary>{{ $summary }} <small lang="en">- edit or remove</small></summary>
        <form method="post" action="{{ route('identifiers.update', [$kind, $subject->getKey(), $identifier]) }}">
            @csrf
            @include('description._identifier-fields', ['identifier' => $identifier, 'prefix' => 'i'.$loop->index])
            <button type="submit">Save identifier</button>
        </form>
        <form method="post" action="{{ route('identifiers.destroy', [$kind, $subject->getKey(), $identifier]) }}">
            @csrf
            <button type="submit">Remove identifier</button>
        </form>
        <p><strong>Persistent IRI:</strong> <code>{{ $identifier->iri }}</code></p>
    </details>
@empty
    <p>None yet.</p>
@endforelse
@if($canUpdate)
<details>
    <summary>Add an identifier</summary>
    <form method="post" action="{{ route('identifiers.store', [$kind, $subject->getKey()]) }}">
        @csrf
        @include('description._identifier-fields', ['identifier' => null, 'prefix' => 'new-identifier'])
        <button type="submit">Add identifier</button>
    </form>
</details>
@endif
