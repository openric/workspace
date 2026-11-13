{{-- OpenRiC Workspace - a published unit of description (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $view->label($unit))
@section('head')<link rel="alternate" type="application/ld+json" href="{{ route('api.record', $unit->id) }}">@endsection
@section('content')
    {{-- Its display profile (phase 20d): the elements, labels, tree, image size and actions the institution chose for its level. --}}
    @php $display = app(\App\Display\Displays::class); $profile = $display->profileFor($unit); $thumb = $display->thumbnailWidth($profile);
        $withTree = ($tree['ancestors'] !== [] || $tree['children'] !== []) && ($profile?->show_tree ?? true); @endphp
    @if($withTree)<div class="with-tree"><aside class="tree-aside">@include('records._tree', ['tree' => $tree, 'branch' => 'discover.record.tree'])</aside><div class="with-tree-main">@endif
    @if($ancestors)<nav aria-label="{{ __('Hierarchy') }}" class="meta">@foreach($ancestors as $above)<a href="{{ route('discover.record', $above->id) }}">{{ $view->label($above) }}</a> / @endforeach</nav>@endif
    @php($alsoIn = $unit->memberships()->with('recordSet')->get()->filter(fn ($m) => $view->readable($m->recordSet)))
    @if($alsoIn->isNotEmpty())<p class="meta">{{ __('Also in') }}: @foreach($alsoIn as $m)<a href="{{ route('discover.record', $m->record_set_id) }}">{{ $view->label($m->recordSet) }}</a>@if(! $loop->last), @endif @endforeach</p>@endif
    {{-- Phase 26: its title and what can be done with it above the images, as the approved mock-up, then three boxes after The National Archives' record pages. --}}
    @php([$title, $titleLanguage] = $view->valueWithLanguage($unit, 'title') ?? ['', 'en'])
    <h1 class="title" lang="{{ $titleLanguage }}">{{ $title }}</h1>
    <div class="actions no-print">
    @if($display->allows($profile, 'clipboard'))@include('clipboard._button', ['type' => 'records', 'id' => $unit->id])@endif
    @if($display->allows($profile, 'favourites'))@include('favourites._button', ['entity' => $unit])@endif
    @if($display->allows($profile, 'order') && \App\Support\Modules::on('shop'))@include('shop._add', ['unit' => $unit])@endif
    @if($display->allows($profile, 'cite'))<a href="{{ route('cite', $unit->id) }}" class="button-tertiary">{{ __('Cite') }}</a>@endif
    {{-- Its DOI, once findable (phase 20c). --}}
    @php($unitDoi = \App\Models\RecordDoi::where('record_resource_id', $unit->id)->whereHas('state', fn ($q) => $q->where('notation', 'findable'))->first())
    @if($unitDoi)<span style="font-size: .85rem"> · DOI <a href="{{ $unitDoi->url() }}">{{ $unitDoi->doi }}</a></span>@endif
    @if($display->allows($profile, 'feedback'))<a href="{{ route('feedback.create', ['record' => $unit->id]) }}" class="button-tertiary">{{ __('Feedback on this record') }}</a>@endif
    {{-- Repatriation (phase 17d2): the claims about it, where the register is shown, and lodging one, where the institution allows it. --}}
    @php($repatriation = \App\Models\RepatriationSetting::for($institution->id))
    @if($repatriation->public_register && \App\Support\Modules::on('repatriation'))@foreach(\App\Models\RepatriationClaim::where('record_resource_id', $unit->id)->get() as $rc)<a href="{{ route('discover.repatriation.show', $rc->id) }}" class="no-print" style="font-size: .85rem">{{ __('Virtual return') }}</a> @endforeach @endif
    {{-- Languages and research leads (phase 17d4). --}}
    @module('scholarship')<a href="{{ route('discover.transcribe', $unit->id) }}" class="no-print" style="font-size: .85rem">{{ __('Transcribe or translate') }}</a>@endmodule
    @php($publishedLead = \App\Models\ResearchLead::where(['record_resource_id' => $unit->id, 'status_id' => \App\Scholarship\ResearchLeads::status('published')])->first())
    @if($publishedLead && \App\Support\Modules::on('scholarship'))<a href="{{ route('discover.research-leads.show', $publishedLead->id) }}" class="no-print" style="font-size: .85rem">{{ __('Research lead') }}</a>@endif
    @if($repatriation->public_lodging && \App\Support\Modules::on('repatriation'))<a href="{{ route('discover.repatriation.lodge', $unit->id) }}" class="no-print" style="font-size: .85rem">{{ __('Lodge a repatriation claim') }}</a>@endif
    </div>
    {{-- The images under the title and above the description, as AtoM shows the digital object. --}}
    {{-- Heratio's imageflow: the pictures of the units inside, each opening its unit. --}}
    @if($childThumbnails->isNotEmpty() && $thumb !== 0)
        <section aria-label="{{ __('Pictures of the units inside') }}" class="imageflow">
            <ul>
                @foreach($childThumbnails as ['unit' => $child, 'file' => $file])
                    <li><a href="{{ route('discover.record', $child->id) }}" title="{{ $view->label($child) }}">
                        <img src="{{ route('discover.file', $file) }}" alt="" width="{{ $file->width }}" height="{{ $file->height }}" loading="lazy" @if($thumb) style="width: {{ $thumb }}px; height: auto" @endif>
                        <span>{{ \Illuminate\Support\Str::limit((string) $view->label($child), 28) }}</span></a></li>
                @endforeach
            </ul>
            @if($childThumbnailTotal > $childThumbnails->count())<p class="meta">{{ __('Showing :shown of :total pictures of the units inside.', ['shown' => $childThumbnails->count(), 'total' => $childThumbnailTotal]) }}</p>@endif
        </section>
    @endif
    @if($embargo)
        <p role="note" class="meta"><strong>{{ __('Under embargo') }}</strong>@if($embargo->ends_on) {{ __('until :date', ['date' => $embargo->ends_on->format('j F Y')]) }}@endif:
            {{ $embargo->public_message ?? __('some or all of its digital copies are withheld.') }}</p>
    @endif
    @if($instantiations->isNotEmpty())
        <section aria-label="{{ __('Digital copies and carriers') }}">
        @foreach($instantiations as ['instantiation' => $instantiation, 'files' => $files])
            <figure style="margin: 1rem 0">
                @include('description._viewer', ['instantiation' => $instantiation, 'files' => $files, 'title' => $view->label($instantiation), 'alt' => $view->value($instantiation, 'instantiation-alt-text'), 'fileRoute' => 'discover.file'])
                <figcaption>{{ $view->label($instantiation) }} @include('description._ric', ['term' => 'rico:Instantiation'])
                    <span class="meta">{{ collect([$instantiation->carrierType?->label(\App\Support\Vocab::languages())])->filter()->join('') }}</span>
                    @include('discover._rights', ['subject' => $instantiation, 'view' => $view, 'compact' => true])
                    @if($instantiation->availability->notation === 'on-request') · <a href="{{ route('access-requests.create', $instantiation) }}">{{ __('Available on request: ask for access') }}</a>@endif
                    @if($files->has('master') && $display->allows($profile, 'download')) · <a href="{{ route('discover.file', [$files['master'], 'download' => 1]) }}">{{ __('Download the master (:type, :size, SHA-256 :sha...)', ['type' => $files['master']->mime_type, 'size' => \Illuminate\Support\Number::fileSize($files['master']->size_bytes, 1), 'sha' => substr($files['master']->sha256, 0, 12)]) }}</a>@endif</figcaption>
            </figure>
        @endforeach
        </section>
    @endif

    {{-- About these records, size and reference, can I see them: the questions a visitor comes with (phase 26, after TNA Discovery). --}}
    @php($access = $view->value($unit, 'conditions-governing-access'))
    @php($extent = $view->value($unit, 'extent'))
    <div class="record-boxes">
        <section class="panel">
            <h2>{{ __('About these records') }}</h2>
            <dl class="summary">
                <div><dt>{{ __('Level') }}</dt><dd>{{ $unit->level->label(\App\Support\Vocab::languages()) }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($unit)])</dd></div>
                @foreach($unit->dates as $date)<div><dt>{{ $date->property->label(\App\Support\Vocab::languages()) }}</dt><dd>{{ $date->display() }} @include('description._ric', ['term' => $date->property])</dd></div>@endforeach
            </dl>
            <p style="margin-bottom: 0"><a href="{{ route('discover.graph', ['records', $unit->id]) }}">{{ __('See it in the relationship graph') }}</a></p>
        </section>
        <section class="panel">
            <h2>{{ __('Size and reference') }}</h2>
            <dl class="summary">
                @if($unit->referenceCode())<div><dt>{{ __('Reference code') }}</dt><dd>{{ $unit->referenceCode() }}</dd></div>@endif
                @if($extent)<div><dt>{{ __('Extent') }}</dt><dd>{{ \Illuminate\Support\Str::limit($extent, 120) }}</dd></div>@endif
                @if($unit->repository && $view->readable($unit->repository))<div><dt>{{ __('Held by') }}</dt><dd><a href="{{ route('discover.repository', $unit->repository->id) }}">{{ $view->label($unit->repository) }}</a></dd></div>@endif
            </dl>
        </section>
        <section class="panel">
            <h2>{{ __('Can I see them?') }}</h2>
            @if($embargo)<p><span class="badge warn">{{ __('Under embargo') }}</span></p>@endif
            <p style="margin-bottom: 0">{{ $access ? \Illuminate\Support\Str::limit($access, 240) : __('Ask the institution that holds them how to consult them.') }}</p>
        </section>
    </div>

    @php($creators = $view->creators($unit))
    @if($creators->isNotEmpty())
        <h2>{{ __('Creator(s)') }}</h2>
        <ul>@foreach($creators as $relation)<li>@php($url = \App\Discovery\Links::page($relation->other))@if($url)<a href="{{ $url }}">{{ $view->label($relation->other) }}</a>@else{{ $view->label($relation->other) }}@endif @include('description._ric', ['term' => $relation->ricTerm()])</li>@endforeach</ul>
    @endif

    @include('discover._values', ['subject' => $unit, 'standard' => \App\Description\Elements::ISAD_G, 'view' => $view, 'display' => $display, 'profile' => $profile])
    @include('discover._relations', ['relations' => $relations, 'view' => $view, 'heading' => __('Access points'), 'types' => ['subject', 'place']])
    @include('discover._relations', ['relations' => $relations, 'view' => $view, 'heading' => __('Documentary form types'), 'types' => ['documentary-form']])
    @include('discover._relations', ['relations' => $relations, 'view' => $view, 'heading' => __('Related'), 'except' => ['subject', 'place', 'documentary-form', 'creation', 'accumulation']])

    @if($children->isNotEmpty())
        <h2>{{ __('Inside it (:count)', ['count' => $childTotal]) }}</h2>
        <ul>@foreach($children as $child)<li><a href="{{ route('discover.record', $child->id) }}">{{ $view->label($child) }}</a> <span class="meta">{{ collect([$child->level->label(\App\Support\Vocab::languages()), $child->referenceCode(), $child->dates->first()?->display()])->filter()->join(', ') }}</span></li>@endforeach</ul>
        @if($childPages > 1)
            <p class="meta">{{ __('Page :page of :pages', ['page' => $childPage, 'pages' => $childPages]) }}@if($childPage > 1) · <a href="{{ request()->fullUrlWithQuery(['inside' => $childPage - 1]) }}">{{ __('Previous page') }}</a>@endif @if($childPage < $childPages) · <a href="{{ request()->fullUrlWithQuery(['inside' => $childPage + 1]) }}">{{ __('Next page') }}</a>@endif</p>
        @endif
    @endif
    @include('discover._rights', ['subject' => $unit, 'view' => $view])
    {{-- Its provenance, unless its overview keeps it from the public (roadmap phase 9e). --}}
    @php($custody = $unit->provenanceOverview?->is_public === false ? collect() : $unit->provenanceEntries()->with('owner', 'ownerType.labels', 'transferType.labels', 'certainty.labels', 'startQualifier.labels', 'endQualifier.labels')->get())
    @if($custody->isNotEmpty())
        <h2>{{ __('Provenance') }}</h2>
        @if($statement = $unit->provenanceOverview?->summary)<p style="white-space: pre-line">{{ $statement }}</p>@endif
        @include('records._custody', ['steps' => $custody, 'languages' => \App\Support\Vocab::languages(), 'public' => true, 'view' => $view])
    @endif
    @include('discover._identifiers', ['entity' => $unit])
    @include('search._related', ['unit' => $unit, 'public' => true, 'route' => 'discover.record'])
    <h2>{{ __('Cite and reuse') }}</h2>
    <p>{{ collect([$title, $unit->referenceCode(), $unit->repository ? $view->label($unit->repository) : null])->filter()->join(', ') }}. <code>{{ $unit->iri }}</code></p>
    <p class="meta">{{ __('This description as RiC-O') }}: <a class="chip" href="{{ route('api.record', $unit->id) }}">JSON-LD</a> <a class="chip" href="{{ route('api.export', [$unit->id, 'format' => 'ttl']) }}">Turtle</a> <a class="chip" href="{{ route('api.export', [$unit->id, 'format' => 'rdf']) }}">RDF/XML</a></p>
    @if($iiif)<p class="meta">{{ __('Its images as IIIF') }}: <a href="{{ route('discover.iiif', $unit->id) }}">{{ __('Open in Mirador') }}</a> · <a href="{{ route('iiif.manifest', $unit->id) }}">{{ __('IIIF manifest') }}</a></p>@endif
    @if($withTree)</div></div>@endif
    @include('custom-fields._panel', ['entity' => $unit, 'kind' => $kind ?? 'records', 'public' => true])
@endsection
