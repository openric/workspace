{{-- OpenRiC Workspace - one instantiation and its files. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $instantiation->described('instantiation-title', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('records.show', $unit) }}#instantiations">Back to {{ $unit->described('title', ['en']) }}</a></p>
    <h1>{{ $instantiation->described('instantiation-title', [app()->getLocale(), 'en']) }}</h1>
    <p>Instantiation @include('description._ric', ['term' => 'rico:Instantiation']) of <a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', ['en']) }}</a> @include('description._ric', ['term' => 'rico:isOrWasInstantiationOf'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $instantiation->iri }}</code>
        <br><strong>{{ __('Carrier type') }}:</strong> {{ $instantiation->carrierType?->label(['en']) ?? 'not set' }} @include('description._ric', ['term' => 'rico:hasCarrierType'])
        <br><strong>{{ __('Availability') }}:</strong> {{ $instantiation->availability->label(['en']) }}: {{ $instantiation->availability->labels->firstWhere('language', 'en')?->definition }}</p>
    @if($canUpdate)
        @include('description._actions', ['subject' => $instantiation, 'editRoute' => 'instantiations.edit', 'required' => 'instantiation-title'])
    @endif

    @include('description._viewer', ['instantiation' => $instantiation, 'files' => $instantiation->files->filter(fn ($f) => $deliverable[$f->id])->keyBy(fn ($f) => $f->use->notation),
        'title' => $instantiation->described('instantiation-title', ['en']), 'fileRoute' => 'files.show'])
    <h2>{{ __('Files') }}</h2>
    @forelse($instantiation->files->sortBy(fn ($f) => array_search($f->use->notation, ['master', 'reference', 'thumbnail'])) as $file)
        <section style="border-top: 1px solid var(--line); padding: .5rem 0">
            <h3 style="margin: .2rem 0">{{ $file->use->label(['en']) }}</h3>
            <p>{{ $file->original_name }}<br>
                {{ $file->mime_type }} @include('description._ric', ['term' => 'openricx:hasMimeType']) · {{ \Illuminate\Support\Number::fileSize($file->size_bytes, 1) }} ({{ number_format($file->size_bytes) }} bytes) @include('description._ric', ['term' => 'rico:hasExtent'])
                @if($file->width) · {{ $file->width }} by {{ $file->height }} pixels @endif
                <br>SHA-256 @include('description._ric', ['term' => 'openricx:hasChecksum']) <code style="word-break: break-all">{{ $file->sha256 }}</code>
                <br>@if($file->fixity_failed_at)<strong role="alert">Fixity check failed on {{ $file->fixity_failed_at->format('Y-m-d H:i') }}: the kept file is missing or changed. Restore it from a backup.</strong>
                    @elseif($file->fixity_checked_at)Fixity last checked {{ $file->fixity_checked_at->format('Y-m-d H:i') }}: unchanged.@endif
                @if($file->use->notation === 'master')<br>{{ $file->scanned_with ? 'Scanned for viruses with '.$file->scanned_with.' on '.$file->scanned_at->format('Y-m-d H:i').'.' : 'Not scanned for viruses: no scanner was configured.' }}@endif</p>
            <p>@if($deliverable[$file->id])<a href="{{ route('files.show', $file) }}">{{ __('Open') }}</a> · <a href="{{ route('files.show', [$file, 'download' => 1]) }}">{{ __('Download') }}</a>@else<small>You may not receive this file: its use needs the read-{{ $file->use->notation }} permission, and the instantiation is {{ mb_strtolower($instantiation->availability->label(['en'])) }}.</small>@endif
                @if($canUpdate) <form method="post" action="{{ route('files.fixity', $file) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Check fixity now') }}</button></form>@endif</p>
        </section>
    @empty
        <p>{{ __('No file: a carrier recorded without one, such as the paper original.') }}</p>
    @endforelse

    @include('description._view', ['subject' => $instantiation, 'standard' => \App\Description\Elements::INSTANTIATION, 'drafts' => $canUpdate])
    @include('description._names', ['subject' => $instantiation, 'kind' => 'instantiations', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $instantiation, 'kind' => 'instantiations', 'canUpdate' => $canUpdate])
    @include('description._dates', ['subject' => $instantiation, 'kind' => 'instantiations', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])
    @if(auth()->user()->mayAnywhere('read', 'storage'))
        @include('description._relations', ['subject' => $instantiation, 'kind' => 'instantiations', 'relations' => $relations->filter(fn ($r) => auth()->user()->may('read', 'storage', $r->other)), 'canUpdate' => $canUpdate,
            'heading' => 'Physical storage', 'types' => ['storage'], 'section' => 'ps', 'add' => 'storage|source', 'addLabel' => 'Add a container it is kept in'])
    @endif
@endsection
