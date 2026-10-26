{{-- OpenRiC Workspace - one unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $record->described('title', ['en']))
@section('content')
    @php $withTree = $tree['ancestors'] !== [] || $tree['children'] !== []; @endphp
    @if($withTree)<div class="with-tree"><aside class="tree-aside">@include('records._tree', ['tree' => $tree, 'branch' => 'records.tree-branch'])</aside><div class="with-tree-main">@endif
    <nav aria-label="Hierarchy"><a href="{{ route('records.index') }}">Archival descriptions</a>
        @foreach($record->ancestors() as $ancestor) / <a href="{{ route('records.show', $ancestor) }}">{{ $ancestor->described('title', ['en']) }}</a>@endforeach</nav>
    @php
        // Each instantiation's files this account may receive, by use, for its viewer.
        $preview = $record->instantiations->map(fn ($i) => [$i, $i->files->filter(fn ($f) => app(\App\Description\InstantiationService::class)->deliverable(auth()->user(), $f->setRelation('instantiation', $i)))
            ->keyBy(fn ($f) => $f->use->notation)])->filter(fn ($p) => $p[1]->isNotEmpty());
    @endphp
    {{-- Heratio's imageflow: the pictures of the units inside, each opening its unit. --}}
    @if($childThumbnails['tiles']->isNotEmpty())
        <section aria-label="Pictures of the units inside" style="margin: 1rem 0; padding: .6rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius)">
            <ul style="display: flex; gap: .6rem; overflow-x: auto; list-style: none; margin: 0; padding: 0 0 .3rem">
                @foreach($childThumbnails['tiles'] as ['unit' => $child, 'file' => $file])
                    <li style="flex: none; width: 128px; margin: 0"><a href="{{ route('records.show', $child) }}" title="{{ $child->described('title', ['en']) }}" style="display: block; text-decoration: none; font-size: .8rem; text-align: center; color: var(--muted-2)">
                        <img src="{{ route('files.show', $file) }}" alt="" width="{{ $file->width }}" height="{{ $file->height }}" loading="lazy" style="width: 128px; height: 96px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); display: block; margin-bottom: .25rem">
                        {{ \Illuminate\Support\Str::limit((string) $child->described('title', ['en']), 28) }}</a></li>
                @endforeach
            </ul>
            @if($childThumbnails['total'] > $childThumbnails['tiles']->count())<p class="meta">Showing {{ $childThumbnails['tiles']->count() }} of {{ $childThumbnails['total'] }} pictures of the units inside.</p>@endif
        </section>
    @endif
    @if($preview->isNotEmpty())
        {{-- The images first, as AtoM shows the digital object above the description; the instantiations are managed below. --}}
        <section aria-label="Digital copies" style="display: flex; gap: 1rem; flex-wrap: wrap; margin: 1rem 0">
            @foreach($preview as [$instantiation, $files])
                <div style="flex: 1 1 100%; min-width: 0">@include('description._viewer', ['instantiation' => $instantiation, 'files' => $files, 'title' => $instantiation->described('instantiation-title', [app()->getLocale(), 'en']), 'fileRoute' => 'files.show'])</div>
            @endforeach
        </section>
    @endif
    <h1>{{ $record->described('title', ['en']) }}</h1>
    @include('clipboard._button', ['type' => 'records', 'id' => $record->id])
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ $record->level->label(\App\Support\Vocab::languages()) }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($record)])@if($record->referenceCode()), {{ $record->referenceCode() }}@endif. Held by <a href="{{ route('repositories.show', $record->repository) }}">{{ $record->repository->described('authorised-name', ['en']) }}</a>.<br>
       <strong>Persistent IRI:</strong> <code>{{ $record->iri }}</code></p>
    <p><a href="{{ route('records.tree', $record) }}">Hierarchy</a> · <a href="{{ route('records.inventory', $record) }}">Inventory</a> · <a href="{{ route('records.finding-aid', $record) }}">Finding aid</a> · <a href="{{ route('records.print', $record) }}">Print view</a> · <a href="{{ route('records.export', $record) }}">Export</a>
       @if($canUpdate) · <a href="{{ route('history', ['records', $record->id]) }}">History</a>
           · <form method="post" action="{{ route('records.calculate-dates', $record) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">Calculate dates from the units inside</button></form>@endif</p>
    <p>@if($canUpdate)<a href="{{ route('records.move-form', $record) }}">Move</a>@endif
       @if($canUpdate && $canCreateInside) · @endif
       @if($canCreateInside)<a href="{{ route('records.create', ['parent' => $record->id]) }}">Add a unit inside</a> · <a href="{{ route('imports.create', ['parent' => $record->id]) }}">Import units into it</a>@endif</p>
    @if($canUpdate || auth()->user()->may('translate', 'description', $record))
        @include('description._actions', ['subject' => $record, 'editRoute' => 'records.edit', 'required' => 'title'])
    @endif
    @include('description._view', ['subject' => $record, 'standard' => \App\Description\Elements::ISAD_G, 'drafts' => $drafts])
    @include('records._creators', ['relations' => $relations, 'drafts' => $drafts])
    @include('records._provenance', ['record' => $record, 'relations' => $relations])
    @include('records._instantiations', ['record' => $record, 'canUpdate' => $canUpdate])
    <h2>Description control</h2>
    <p>Description identifier: {{ $record->description_identifier ?? 'none' }}. Status: {{ $record->descriptionStatus?->label(\App\Support\Vocab::languages()) ?? 'not set' }}. Level of detail: {{ $record->descriptionDetail?->label(\App\Support\Vocab::languages()) ?? 'not set' }}.</p>
    <h2>Cite as</h2>
    <p>{{ collect([$record->described('title', ['en']), $record->referenceCode(), $record->repository->described('authorised-name', ['en'])])->filter()->join(', ') }}. {{ $record->iri }} (accessed {{ now()->format('j F Y') }}).</p>

    @include('description._names', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate, 'drafts' => $drafts])
    @include('description._identifiers', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Subject access points', 'types' => ['subject'], 'section' => 'su', 'add' => 'subject|source', 'addLabel' => 'Add a subject: a concept, a place or an agent'])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Place access points', 'types' => ['place'], 'section' => 'pl', 'add' => 'place|target', 'addLabel' => 'Add a place'])
    @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Documentary form types (genre access points)', 'types' => ['documentary-form'], 'section' => 'df', 'add' => 'documentary-form|source', 'addLabel' => 'Add a documentary form type'])
    @if(auth()->user()->mayAnywhere('read', 'storage'))
        @include('description._relations', ['subject' => $record, 'kind' => 'records', 'relations' => $relations->filter(fn ($r) => auth()->user()->may('read', 'storage', $r->other)), 'canUpdate' => $canUpdate,
            'heading' => 'Physical storage', 'types' => ['storage'], 'section' => 'ps', 'add' => 'storage|source', 'addLabel' => 'Add a container it is kept in'])
    @endif
    @include('description._dates', ['subject' => $record, 'kind' => 'records', 'heading' => 'Dates (3.1.3)', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])

    <h2>Units inside</h2>
    @forelse($record->children as $child)
        <div>
            <a href="{{ route('records.show', $child) }}">{{ $child->described('title', ['en']) }}</a> ({{ $child->level->label(\App\Support\Vocab::languages()) }})
            @include('description._row-actions', ['entity' => $child])
            @if(auth()->user()->may('update', 'description', $child))
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="up"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} up">Up</button></form>
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="down"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} down">Down</button></form>
            @endif
        </div>
    @empty
        <p>None yet.</p>
    @endforelse
    @if($withTree)</div></div>@endif
@endsection
