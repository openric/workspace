{{-- OpenRiC Workspace - one unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $record->described('title', ['en']))
@section('content')
    @php $withTree = $tree['ancestors'] !== [] || $tree['children'] !== []; @endphp
    @if($withTree)<div class="with-tree"><aside class="tree-aside">@include('records._tree', ['tree' => $tree, 'branch' => 'records.tree-branch'])</aside><div class="with-tree-main">@endif
    <nav aria-label="{{ __('Hierarchy') }}"><a href="{{ route('records.index') }}">{{ __('Archival descriptions') }}</a>
        @foreach($record->ancestors() as $ancestor) / <a href="{{ route('records.show', $ancestor) }}">{{ $ancestor->described('title', ['en']) }}</a>@endforeach</nav>
    @php
        // Each instantiation's files this account may receive, by use, for its viewer.
        $preview = $record->instantiations->map(fn ($i) => [$i, $i->files->filter(fn ($f) => app(\App\Description\InstantiationService::class)->deliverable(auth()->user(), $f->setRelation('instantiation', $i)))
            ->keyBy(fn ($f) => $f->use->notation)])->filter(fn ($p) => $p[1]->isNotEmpty());
    @endphp
    {{-- Heratio's imageflow: the pictures of the units inside, each opening its unit. --}}
    @if($childThumbnails['tiles']->isNotEmpty())
        <section aria-label="{{ __('Pictures of the units inside') }}" style="margin: 1rem 0; padding: .6rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius)">
            <ul style="display: flex; gap: .6rem; overflow-x: auto; list-style: none; margin: 0; padding: 0 0 .3rem">
                @foreach($childThumbnails['tiles'] as ['unit' => $child, 'file' => $file])
                    <li style="flex: none; width: 128px; margin: 0"><a href="{{ route('records.show', $child) }}" title="{{ $child->described('title', ['en']) }}" style="display: block; text-decoration: none; font-size: .8rem; text-align: center; color: var(--muted-2)">
                        <img src="{{ route('files.show', $file) }}" alt="" width="{{ $file->width }}" height="{{ $file->height }}" loading="lazy" style="width: 128px; height: 96px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); display: block; margin-bottom: .25rem">
                        {{ \Illuminate\Support\Str::limit((string) $child->described('title', ['en']), 28) }}</a></li>
                @endforeach
            </ul>
            @if($childThumbnails['total'] > $childThumbnails['tiles']->count())<p class="meta">{{ __('Showing :shown of :total pictures of the units inside.', ['shown' => $childThumbnails['tiles']->count(), 'total' => $childThumbnails['total']]) }}</p>@endif
        </section>
    @endif
    @if($preview->isNotEmpty())
        {{-- The images first, as AtoM shows the digital object above the description; the instantiations are managed below. --}}
        <section aria-label="{{ __('Digital copies') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin: 1rem 0">
            @foreach($preview as [$instantiation, $files])
                <div style="flex: 1 1 100%; min-width: 0">@include('description._viewer', ['instantiation' => $instantiation, 'files' => $files, 'title' => $instantiation->described('instantiation-title', [app()->getLocale(), 'en']),
                    'alt' => $instantiation->described('instantiation-alt-text', [app()->getLocale(), 'en']), 'fileRoute' => 'files.show'])</div>
            @endforeach
        </section>
    @endif
    <h1>{{ $record->described('title', ['en']) }}</h1>
    @include('clipboard._button', ['type' => 'records', 'id' => $record->id])
    @include('favourites._button', ['entity' => $record])
    <a href="{{ route('cite', $record->id) }}" class="no-print" style="font-size: .85rem">{{ __('Cite') }}</a>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ $record->level->label(\App\Support\Vocab::languages()) }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($record)])@if($record->referenceCode()), {{ $record->referenceCode() }}@endif{{ __('. Held by') }} <a href="{{ route('repositories.show', $record->repository) }}">{{ $record->repository->described('authorised-name', ['en']) }}</a>.<br>
       <strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $record->iri }}</code></p>
    <p><a href="{{ route('records.tree', $record) }}">{{ __('Hierarchy') }}</a> · <a href="{{ route('records.inventory', $record) }}">{{ __('Inventory') }}</a> · <a href="{{ route('records.finding-aid', $record) }}">{{ __('Finding aid') }}</a> · <a href="{{ route('records.print', $record) }}">{{ __('Print view') }}</a> · <a href="{{ route('records.export', $record) }}">{{ __('Export') }}</a>@if(auth()->user()->may('update', 'description', $record)) · <a href="{{ route('research.rights-policies', $record) }}">{{ __('Rights policies for researchers') }}</a>@endif
       @unless($canUpdate) · <a href="{{ route('access-requests.new', ['record' => $record->id, 'scope' => in_array($record->level->notation, \App\AccessRequests\AccessRequestService::COLLECTION_LEVELS, true) ? 'collection' : 'item']) }}">{{ __('Ask for access') }}</a>@endunless
       @if($canUpdate) · <a href="{{ route('history', ['records', $record->id]) }}">{{ __('History') }}</a>
           · <form method="post" action="{{ route('records.calculate-dates', $record) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Calculate dates from the units inside') }}</button></form>@endif</p>
    <p>@if($canUpdate)<a href="{{ route('records.move-form', $record) }}">{{ __('Move') }}</a>@endif
       @if($canUpdate && $canCreateInside) · @endif
       @if($canCreateInside)<a href="{{ route('records.create', ['parent' => $record->id]) }}">{{ __('Add a unit inside') }}</a> · <a href="{{ route('imports.create', ['parent' => $record->id]) }}">{{ __('Import units into it') }}</a>@endif</p>
    @if($canUpdate || auth()->user()->may('translate', 'description', $record))
        @include('description._actions', ['subject' => $record, 'editRoute' => 'records.edit', 'required' => 'title'])
    @endif
    @if($record->resourceTypes->isNotEmpty())<p><strong>{{ __('Types') }}:</strong> {{ $record->resourceTypes->map(fn ($t) => $t->type->label(\App\Support\Vocab::languages()))->join(', ') }}</p>@endif
    @include('description._view', ['subject' => $record, 'standard' => $record->standard(), 'drafts' => $drafts])
    @include('records._creators', ['relations' => $relations, 'drafts' => $drafts])
    @include('records._provenance', ['record' => $record, 'relations' => $relations])
    @include('records._instantiations', ['record' => $record, 'canUpdate' => $canUpdate])
    <h2>{{ __('Description control') }}</h2>
    <p>{{ __('Description identifier') }}: {{ $record->description_identifier ?? __('none') }}.
        {{ __('Status') }}: {{ $record->descriptionStatus?->label(\App\Support\Vocab::languages()) ?? __('not set') }}.
        {{ __('Level of detail') }}: {{ $record->descriptionDetail?->label(\App\Support\Vocab::languages()) ?? __('not set') }}.
        @if($record->source_standard){{ __('Source standard') }}: {{ $record->source_standard }}.@endif</p>
    <h2>{{ __('Cite as') }}</h2>
    <p>{{ collect([$record->described('title', ['en']), $record->referenceCode(), $record->repository->described('authorised-name', ['en'])])->filter()->join(', ') }}. {{ $record->iri }} ({{ __('accessed :date', ['date' => now()->translatedFormat('j F Y')]) }}).</p>

    @include('description._names', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate, 'drafts' => $drafts])
    @include('description._identifiers', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate])
    @include('workflow._subject', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate])
    <section id="custody">
        <h2>{{ __('Provenance and custody history') }} @include('description._ric', ['term' => 'rico:isOrWasAffectedBy'])</h2>
        @php($custody = $record->provenanceEntries()->with('ownerType.labels', 'transferType.labels', 'certainty.labels', 'startQualifier.labels', 'endQualifier.labels')->get())
        @if($statement = $record->provenanceOverview?->summary)<p style="white-space: pre-line">{{ $statement }}</p>@endif
        @if($custody->isNotEmpty())@include('records._custody', ['steps' => $custody, 'languages' => \App\Support\Vocab::languages()])@else<p>{{ __('No provenance entries yet.') }}</p>@endif
        <p><a href="{{ route('records.provenance', $record) }}">{{ $canUpdate ? __('Manage provenance') : __('Full provenance') }}</a></p>
    </section>
    @include('description._rights', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate])
    @include('records._embargoes', ['record' => $record, 'canUpdate' => $canUpdate])
    @if(auth()->user()->hasPermission(\App\Support\Vocab::permission('manage-privacy')))@include('records._privacy', ['record' => $record])@endif
    @if(app(\App\Research\ShareLinks::class)->mayIssue(auth()->user(), $record))@include('records._share-links', ['record' => $record])@endif
    @if(auth()->user()->may('delete', 'description', $record))@include('records._retention', ['record' => $record]) @include('records._records-management', ['record' => $record]) @include('records._classification', ['record' => $record])@endif
    @include('description._community-labels', ['subject' => $record, 'kind' => 'records', 'canUpdate' => $canUpdate])
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

    {{-- In record sets besides its parent, each a rico:Proxy with its own place there (roadmap phase 24). --}}
    <h2>{{ __('Also in') }} @include('description._ric', ['term' => 'rico:isOrWasIncludedIn'])</h2>
    @forelse($record->memberships()->with('recordSet')->get() as $membership)
        <div><a href="{{ route('records.show', $membership->recordSet) }}">{{ $membership->recordSet->described('title', ['en']) }}</a>
            <small>{{ __('as') }} <code>{{ $membership->iri }}</code></small>
            @if(auth()->user()->may('update', 'description', $membership->recordSet))
                <form method="post" action="{{ route('records.memberships.step', [$membership, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ __('Take out') }}</button></form>
            @endif
        </div>
    @empty
        <p>{{ __('Only where it is arranged, under the unit above it.') }}</p>
    @endforelse
    @if($canUpdate)
        <form method="post" action="{{ route('records.memberships.store', $record) }}">
            @csrf
            <label for="record_set">{{ __('Put it in another record set as well') }}</label> @include('description._ric', ['term' => 'rico:Proxy'])
            <input id="record_set" name="record_set" required value="{{ old('record_set') }}" aria-describedby="record-set-help">
            <small id="record-set-help">{{ __('The reference code, IRI or identifier of the record set. Its reference code, holder and what it inherits still come from where it is arranged.') }}</small>
            @error('record_set')<p class="error" role="alert">{{ $message }}</p>@enderror
            <button type="submit">{{ __('Add') }}</button>
        </form>
    @endif

    @if($record->memberProxies()->exists())
        <h2>{{ __('Also included here') }} @include('description._ric', ['term' => 'rico:includesOrIncluded'])</h2>
        @foreach($record->memberProxies()->with('record.level')->get() as $proxy)
            <div><a href="{{ route('records.show', $proxy->record) }}">{{ $proxy->record->described('title', ['en']) }}</a> ({{ $proxy->record->level->label(\App\Support\Vocab::languages()) }})
                @if(auth()->user()->may('update', 'description', $record))
                    <form method="post" action="{{ route('records.memberships.step', [$proxy, 'up']) }}" style="display: inline">@csrf<button type="submit" aria-label="{{ __('Move :name up', ['name' => $proxy->record->described('title', ['en'])]) }}">{{ __('Up') }}</button></form>
                    <form method="post" action="{{ route('records.memberships.step', [$proxy, 'down']) }}" style="display: inline">@csrf<button type="submit" aria-label="{{ __('Move :name down', ['name' => $proxy->record->described('title', ['en'])]) }}">{{ __('Down') }}</button></form>
                    <form method="post" action="{{ route('records.memberships.step', [$proxy, 'remove']) }}" style="display: inline">@csrf<button type="submit">{{ __('Take out') }}</button></form>
                @endif
            </div>
        @endforeach
    @endif

    <h2>{{ __('Units inside') }}</h2>
    @forelse($record->children as $child)
        <div>
            <a href="{{ route('records.show', $child) }}">{{ $child->described('title', ['en']) }}</a> ({{ $child->level->label(\App\Support\Vocab::languages()) }})
            @include('description._row-actions', ['entity' => $child])
            @if(auth()->user()->may('update', 'description', $child))
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="up"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} up">{{ __('Up') }}</button></form>
            <form method="post" action="{{ route('records.reorder', $child) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="down"><button type="submit" aria-label="Move {{ $child->described('title', ['en']) }} down">{{ __('Down') }}</button></form>
            @endif
        </div>
    @empty
        <p>{{ __('None yet.') }}</p>
    @endforelse
    @if($withTree)</div></div>@endif
@endsection
