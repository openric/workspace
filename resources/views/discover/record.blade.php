{{-- OpenRiC Workspace - a published unit of description (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $view->label($unit))
@section('head')<link rel="alternate" type="application/ld+json" href="{{ route('api.record', $unit->id) }}">@endsection
@section('content')
    @php $withTree = $tree['ancestors'] !== [] || $tree['children'] !== []; @endphp
    @if($withTree)<div class="with-tree"><aside class="tree-aside">@include('records._tree', ['tree' => $tree, 'branch' => 'discover.record.tree'])</aside><div class="with-tree-main">@endif
    @if($ancestors)<nav aria-label="{{ __('Hierarchy') }}" class="meta">@foreach($ancestors as $above)<a href="{{ route('discover.record', $above->id) }}">{{ $view->label($above) }}</a> / @endforeach</nav>@endif
    {{-- The images first, as AtoM shows the digital object above the description. --}}
    {{-- Heratio's imageflow: the pictures of the units inside, each opening its unit. --}}
    @if($childThumbnails->isNotEmpty())
        <section aria-label="{{ __('Pictures of the units inside') }}" class="imageflow">
            <ul>
                @foreach($childThumbnails as ['unit' => $child, 'file' => $file])
                    <li><a href="{{ route('discover.record', $child->id) }}" title="{{ $view->label($child) }}">
                        <img src="{{ route('discover.file', $file) }}" alt="" width="{{ $file->width }}" height="{{ $file->height }}" loading="lazy">
                        <span>{{ \Illuminate\Support\Str::limit((string) $view->label($child), 28) }}</span></a></li>
                @endforeach
            </ul>
            @if($childThumbnailTotal > $childThumbnails->count())<p class="meta">{{ __('Showing :shown of :total pictures of the units inside.', ['shown' => $childThumbnails->count(), 'total' => $childThumbnailTotal]) }}</p>@endif
        </section>
    @endif
    @if($instantiations->isNotEmpty())
        <section aria-label="{{ __('Digital copies and carriers') }}">
        @foreach($instantiations as ['instantiation' => $instantiation, 'files' => $files])
            <figure style="margin: 1rem 0">
                @include('description._viewer', ['instantiation' => $instantiation, 'files' => $files, 'title' => $view->label($instantiation), 'fileRoute' => 'discover.file'])
                <figcaption>{{ $view->label($instantiation) }} @include('description._ric', ['term' => 'rico:Instantiation'])
                    <span class="meta">{{ collect([$instantiation->carrierType?->label(\App\Support\Vocab::languages())])->filter()->join('') }}</span>
                    @if($files->has('master')) · <a href="{{ route('discover.file', [$files['master'], 'download' => 1]) }}">{{ __('Download the master (:type, :size, SHA-256 :sha...)', ['type' => $files['master']->mime_type, 'size' => \Illuminate\Support\Number::fileSize($files['master']->size_bytes, 1), 'sha' => substr($files['master']->sha256, 0, 12)]) }}</a>@endif</figcaption>
            </figure>
        @endforeach
        </section>
    @endif
    @php([$title, $titleLanguage] = $view->valueWithLanguage($unit, 'title') ?? ['', 'en'])
    <h1 lang="{{ $titleLanguage }}">{{ $title }}</h1>
    @include('clipboard._button', ['type' => 'records', 'id' => $unit->id])
    <p class="meta">{{ $unit->level->label(\App\Support\Vocab::languages()) }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($unit)])
        @if($unit->referenceCode()) · {{ $unit->referenceCode() }}@endif
        @if($unit->repository && $view->readable($unit->repository)) · {{ __('Held by') }} <a href="{{ route('discover.repository', $unit->repository->id) }}">{{ $view->label($unit->repository) }}</a>@endif</p>
    @foreach($unit->dates as $date)<p><strong>{{ $date->property->label(\App\Support\Vocab::languages()) }}:</strong> {{ $date->display() }} @include('description._ric', ['term' => $date->property])</p>@endforeach
    <p><a href="{{ route('discover.graph', ['records', $unit->id]) }}">{{ __('See it in the relationship graph') }}</a></p>

    @php($creators = $view->creators($unit))
    @if($creators->isNotEmpty())
        <h2>{{ __('Creator(s)') }}</h2>
        <ul>@foreach($creators as $relation)<li>@php($url = \App\Discovery\Links::page($relation->other))@if($url)<a href="{{ $url }}">{{ $view->label($relation->other) }}</a>@else{{ $view->label($relation->other) }}@endif @include('description._ric', ['term' => $relation->ricTerm()])</li>@endforeach</ul>
    @endif

    @include('discover._values', ['subject' => $unit, 'standard' => \App\Description\Elements::ISAD_G, 'view' => $view])
    @include('discover._relations', ['relations' => $relations, 'view' => $view, 'heading' => __('Access points'), 'types' => ['subject', 'place']])
    @include('discover._relations', ['relations' => $relations, 'view' => $view, 'heading' => __('Documentary form types'), 'types' => ['documentary-form']])
    @include('discover._relations', ['relations' => $relations, 'view' => $view, 'heading' => __('Related'), 'except' => ['subject', 'place', 'documentary-form', 'creation', 'accumulation']])

    @if($children->isNotEmpty())
        <h2>{{ __('Inside it (:count)', ['count' => $children->count()]) }}</h2>
        <ul>@foreach($children as $child)<li><a href="{{ route('discover.record', $child->id) }}">{{ $view->label($child) }}</a> <span class="meta">{{ collect([$child->level->label(\App\Support\Vocab::languages()), $child->referenceCode(), $child->dates->first()?->display()])->filter()->join(', ') }}</span></li>@endforeach</ul>
    @endif
    @include('discover._identifiers', ['entity' => $unit])
    <h2>{{ __('Cite and reuse') }}</h2>
    <p>{{ collect([$title, $unit->referenceCode(), $unit->repository ? $view->label($unit->repository) : null])->filter()->join(', ') }}. <code>{{ $unit->iri }}</code></p>
    <p class="meta">{{ __('This description as RiC-O') }}: <a href="{{ route('api.record', $unit->id) }}">JSON-LD</a> · <a href="{{ route('api.export', [$unit->id, 'format' => 'ttl']) }}">Turtle</a> · <a href="{{ route('api.export', [$unit->id, 'format' => 'rdf']) }}">RDF/XML</a></p>
    @if($withTree)</div></div>@endif
@endsection
