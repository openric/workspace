{{-- OpenRiC Workspace - one unit of description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $record->described('title', ['en']))
@section('content')
    @php $withTree = $tree['ancestors'] !== [] || $tree['children'] !== []; @endphp
    @if($withTree)<div class="with-tree"><aside class="tree-aside">@include('records._tree', ['tree' => $tree, 'branch' => 'records.tree-branch'])</aside><div class="with-tree-main">@endif
    <nav aria-label="{{ __('Hierarchy') }}"><a href="{{ route('records.index') }}">{{ __('Archival descriptions') }}</a>
        @foreach($record->ancestors() as $ancestor) / <a href="{{ route('records.show', $ancestor) }}">{{ $ancestor->described('title', ['en']) }}</a>@endforeach</nav>
    {{-- Phase 26: where it stands, its title, the two actions most used, the rest under More, then what it is at a glance. Above the images, as the mock-up Dr Johan Pieterse approved (13 November 2026), so the actions are seen first. --}}
    @php
        $states = collect(app(\App\Description\PublicationService::class)->summary($record))->flatMap(fn ($byState) => array_keys($byState))->unique();
        $embargo = app(\App\Rights\Embargoes::class)->inForce($record);
        $waiting = \App\Models\Proposal::where('record_resource_id', $record->id)->whereHas('status', fn ($q) => $q->where('iri', \App\Support\Vocab::ASSERTION_STATUSES.'proposed'))->count();
    @endphp
    <div class="badges">
        @if($states->contains('published'))<span class="badge ok">{{ __('Published') }}</span>@else<span class="badge">{{ __('Not published') }}</span>@endif
        @if($record->descriptionStatus)<span class="badge">{{ $record->descriptionStatus->label(\App\Support\Vocab::languages()) }}</span>@endif
        @if($embargo)<span class="badge warn">{{ __('Embargo in force') }}</span>@endif
        @if($waiting)<a class="badge warn" href="{{ route('proposals.index') }}">{{ trans_choice(':count proposal waiting|:count proposals waiting', $waiting) }}</a>@endif
    </div>
    <h1 class="title">{{ $record->described('title', ['en']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <div class="actions no-print">
        @if($canUpdate || auth()->user()->may('translate', 'description', $record))
            @include('description._actions', ['subject' => $record, 'editRoute' => 'records.edit', 'required' => 'title'])
        @endif
        <details class="more">
            <summary class="button-tertiary">{{ __('More') }}</summary>
            <ul>
                <li>@include('clipboard._button', ['type' => 'records', 'id' => $record->id, 'inMenu' => true])</li>
                <li>@include('favourites._button', ['entity' => $record, 'inMenu' => true])</li>
                <li><a href="{{ route('records.tree', $record) }}">{{ __('Hierarchy') }}</a></li>
                <li><a href="{{ route('records.inventory', $record) }}">{{ __('Inventory') }}</a></li>
                <li><a href="{{ route('records.finding-aid', $record) }}">{{ __('Finding aid') }}</a></li>
                <li><a href="{{ route('records.print', $record) }}">{{ __('Print view') }}</a></li>
                <li><a href="{{ route('records.export', $record) }}">{{ __('Export') }}</a></li>
                <li><a href="{{ route('cite', $record->id) }}">{{ __('Cite') }}</a></li>
                @if(auth()->user()->hasPermission(\App\Support\Vocab::permission('manage-institution')))<li><a href="{{ route('packages.index', $record) }}">{{ __('Information packages') }}</a></li>@endif
                @if(auth()->user()->may('update', 'description', $record))@module('research')<li><a href="{{ route('research.rights-policies', $record) }}">{{ __('Rights policies for researchers') }}</a></li>@endmodule @endif
                @unless($canUpdate)<li><a href="{{ route('access-requests.new', ['record' => $record->id, 'scope' => in_array($record->level->notation, \App\AccessRequests\AccessRequestService::COLLECTION_LEVELS, true) ? 'collection' : 'item']) }}">{{ __('Ask for access') }}</a></li>@endunless
                @if($canUpdate)
                    <li><a href="{{ route('history', ['records', $record->id]) }}">{{ __('History') }}</a></li>
                    <li><form method="post" action="{{ route('records.calculate-dates', $record) }}">@csrf<button type="submit">{{ __('Calculate dates from the units inside') }}</button></form></li>
                    <li><a href="{{ route('records.move-form', $record) }}">{{ __('Move') }}</a></li>
                @endif
                @if($canCreateInside)
                    <li><a href="{{ route('records.create', ['parent' => $record->id]) }}">{{ __('Add a unit inside') }}</a></li>
                    <li><a href="{{ route('imports.create', ['parent' => $record->id]) }}">{{ __('Import units into it') }}</a></li>
                    <li><a href="{{ route('imports.grid', ['parent' => $record->id]) }}">{{ __('Enter units in a grid') }}</a></li>
                @endif
                <li><a href="{{ route('labels.print', ['kind' => 'records', 'ids' => [$record->id]]) }}">{{ __('Label') }}</a></li>
                <li><a href="{{ route('labels.print', ['kind' => 'records', 'ids' => [$record->id], 'inside' => 1]) }}">{{ __('Labels for what is inside') }}</a></li>
                @if(auth()->user()->may('update', 'description', $record))<li><a href="{{ route('versions.index', ['records', $record->id]) }}">{{ __('Versions') }}</a></li>@endif
            </ul>
        </details>
        @include('layouts._ric-switch')
    </div>
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
        {{-- The images under the title and above the description, as AtoM shows the digital object; the instantiations are managed below. --}}
        <section aria-label="{{ __('Digital copies') }}" style="display: flex; gap: 1rem; flex-wrap: wrap; margin: 1rem 0">
            @foreach($preview as [$instantiation, $files])
                <div style="flex: 1 1 100%; min-width: 0">@include('description._viewer', ['instantiation' => $instantiation, 'files' => $files, 'title' => $instantiation->described('instantiation-title', [app()->getLocale(), 'en']),
                    'alt' => $instantiation->described('instantiation-alt-text', [app()->getLocale(), 'en']), 'fileRoute' => 'files.show'])</div>
            @endforeach
        </section>
    @endif
    <section class="panel" aria-label="{{ __('Summary') }}">
        <dl class="summary">
            <div><dt>{{ __('Level') }}</dt><dd>{{ $record->level->label(\App\Support\Vocab::languages()) }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($record)])</dd></div>
            @if($record->referenceCode())<div><dt>{{ __('Reference code') }}</dt><dd>{{ $record->referenceCode() }}</dd></div>@endif
            @if($record->dates->isNotEmpty())<div><dt>{{ __('Dates') }}</dt><dd>{{ $record->dates->map(fn ($d) => $d->display())->join('; ') }}</dd></div>@endif
            @if($extent = $record->described('extent', ['en']))<div><dt>{{ __('Extent') }}</dt><dd>{{ \Illuminate\Support\Str::limit((string) $extent, 80) }}</dd></div>@endif
            <div><dt>{{ __('Held by') }}</dt><dd><a href="{{ route('repositories.show', $record->repository) }}">{{ $record->repository->described('authorised-name', ['en']) }}</a></dd></div>
        </dl>
        <p class="meta" style="margin: .9rem 0 0"><strong>{{ __('Persistent IRI') }}:</strong> <code style="overflow-wrap: anywhere">{{ $record->iri }}</code></p>
    </section>
    <nav class="sections no-print" aria-label="{{ __('Sections of this description') }}">
        <a href="#rights">{{ __('Rights') }}</a>
        <a href="#embargoes">{{ __('Embargoes') }}</a>
        <a href="#instantiations">{{ __('Instantiations') }}</a>
        <a href="#custody">{{ __('Provenance') }}</a>
        <a href="#inside">{{ __('Units inside') }}</a>
    </nav>
    {{-- Phase 17b1: a model finds names or summarises the pages, through the AI gateway; what it answers waits for review. --}}
    @if($canUpdate && app(\App\Ai\Gateway::class)->available($record->institution_id))
        <div class="no-print">
            <form method="post" action="{{ route('records.ask', [$record, 'names']) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0">{{ __('Find names with AI') }}</button></form>
            <form method="post" action="{{ route('records.ask', [$record, 'summary']) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0">{{ __('Summarise its pages with AI') }}</button></form>
            <a href="{{ route('records.translate', $record) }}">{{ __('Translate with AI') }}</a>
            @if(app(\App\Ai\Annif::class)->configured($record->institution_id))<form method="post" action="{{ route('records.ask', [$record, 'subjects']) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0">{{ __('Suggest subjects with Annif') }}</button></form>@endif
            <small>{{ __('People, organisations, places and dates found in its description and the transcripts of its pages, or a summary of those transcripts, proposed for you to review.') }}</small></div>
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
    @module('heritage')@if($canUpdate)@include('records._at-risk', ['record' => $record])@endif @endmodule
    @module('scholarship')@if(auth()->user()->may('publish', 'description', $record))@include('records._scholarship', ['record' => $record])@endif @endmodule
    @module('repatriation')@if(auth()->user()->hasPermission(\App\Support\Vocab::permission('manage-repatriation')))@include('records._repatriation', ['record' => $record])@endif @endmodule
    @module('dois')@include('records._doi', ['record' => $record])@endmodule
    @include('search._related', ['unit' => $record, 'public' => false, 'route' => 'records.show'])
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

    <h2 id="inside">{{ __('Units inside') }}</h2>
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
        @if($insidePage === 1)<p>{{ __('None yet.') }}</p>@endif
    @endforelse
    @if($insidePage > 1 || $moreInside)
        <nav aria-label="{{ __('Units inside') }}"><p>
            @if($insidePage > 1)<a href="{{ route('records.show', [$record, 'inside' => $insidePage - 1]) }}" rel="prev">{{ __('Previous page') }}</a>@endif
            @if($insidePage > 1 && $moreInside) · @endif
            @if($moreInside)<a href="{{ route('records.show', [$record, 'inside' => $insidePage + 1]) }}" rel="next">{{ __('Next page') }}</a>@endif
        </p></nav>
    @endif
    @if($withTree)</div></div>@endif
    @include('custom-fields._panel', ['entity' => $record, 'kind' => 'records'])
@endsection
