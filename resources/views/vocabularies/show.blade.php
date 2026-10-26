{{-- OpenRiC Workspace - one vocabulary: its concepts, a search and the hierarchy. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $vocabulary->title)
@section('content')
    <p><a href="{{ route('vocabularies.index') }}">Vocabularies</a></p>
    <h1>{{ $vocabulary->title }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if($vocabulary->description)<p lang="{{ $vocabulary->language }}">{{ $vocabulary->description }}</p>@endif
    <p><strong>Kind:</strong> {{ $vocabulary->kind->label(\App\Support\Vocab::languages()) }}<br><strong>Persistent IRI:</strong> <code>{{ $vocabulary->iri }}</code></p>
    <p>
        @if(auth()->user()->may('create', 'vocabulary'))<a href="{{ route('vocabularies.concepts.create', $vocabulary) }}">Add a concept</a> · <a href="{{ route('vocabularies.import-form', $vocabulary) }}">Import SKOS</a> · @endif
        @if(auth()->user()->may('update', 'vocabulary'))<a href="{{ route('vocabularies.edit', $vocabulary) }}">Edit title</a> · @endif
        @if(auth()->user()->may('delete', 'vocabulary') && $concepts->isEmpty() && $query === '' && ! $vocabulary->concepts()->exists())
            <form method="post" action="{{ route('vocabularies.destroy', $vocabulary) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0">Delete this empty vocabulary</button></form> ·
        @endif
        Export SKOS: <a href="{{ route('vocabularies.export', [$vocabulary, 'turtle']) }}">Turtle</a>, <a href="{{ route('vocabularies.export', [$vocabulary, 'rdfxml']) }}">RDF/XML</a>, <a href="{{ route('vocabularies.export', [$vocabulary, 'ntriples']) }}">N-Triples</a>, <a href="{{ route('vocabularies.export', [$vocabulary, 'jsonld']) }}">JSON-LD</a>
    </p>
    <form method="get" action="{{ route('vocabularies.show', $vocabulary) }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">Label, alternative label or code</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <label style="margin-top: 0"><input type="checkbox" name="deprecated" value="1" @checked($deprecated)> Include deprecated</label>
        <label style="margin-top: 0"><input type="checkbox" name="view" value="tree" @checked($tree !== null)> As a hierarchy</label>
        <button type="submit" style="margin-top: 0">Show</button>
    </form>
    @php
        $canEdit = auth()->user()->may('update', 'vocabulary');
        $line = fn ($c) => '<a href="'.e(route('concepts.show', $c)).'">'.e($c->anyLabel([app()->getLocale(), 'en'])).'</a>'
            .($c->notation !== null ? ' <small>'.e($c->notation).'</small>' : '')
            .($c->deprecated_at ? ' <small>deprecated</small>' : '')
            .(($uses[$c->id] ?? 0) > 0 ? ' <small>'.$uses[$c->id].' '.($uses[$c->id] === 1 ? 'use' : 'uses').'</small>' : '')
            .($canEdit ? ' <small>· <a href="'.e(route('concepts.edit', $c)).'">Edit</a></small>' : '');
    @endphp
    @if($concepts->isEmpty())
        <p>{{ $query !== '' ? 'No concept matches.' : 'No concepts yet.' }}</p>
    @elseif($tree !== null)
        @php
            // A concept whose broader concept is not shown (deprecated, or filtered out) starts its own branch.
            $shown = $concepts->pluck('id')->flip();
            $roots = $concepts->filter(fn ($c) => $c->broader_id === null || ! isset($shown[$c->broader_id]));
            $branch = function ($concept) use (&$branch, $tree, $line) {
                $children = $tree[$concept->id] ?? collect();

                return '<li>'.$line($concept).($children->isNotEmpty() ? '<ul>'.$children->map($branch)->join('').'</ul>' : '').'</li>';
            };
        @endphp
        <ul>{!! $roots->map($branch)->join('') !!}</ul>
    @else
        <ul>
            @foreach($concepts as $concept)
                <li>{!! $line($concept) !!}@if($concept->broader) <small>in {{ $concept->broader->anyLabel(['en']) }}</small>@endif</li>
            @endforeach
        </ul>
    @endif
@endsection
