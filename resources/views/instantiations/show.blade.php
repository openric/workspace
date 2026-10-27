{{-- OpenRiC Workspace - one instantiation and its files. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $instantiation->described('instantiation-title', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('records.show', $unit) }}#instantiations">{{ __('Back to :name', ['name' => $unit->described('title', ['en'])]) }}</a></p>
    <h1>{{ $instantiation->described('instantiation-title', [app()->getLocale(), 'en']) }}</h1>
    <p>{{ __('Instantiation') }} @include('description._ric', ['term' => 'rico:Instantiation']) of <a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', ['en']) }}</a> @include('description._ric', ['term' => 'rico:isOrWasInstantiationOf'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $instantiation->iri }}</code>
        <br><strong>{{ __('Carrier type') }}:</strong> {{ $instantiation->carrierType?->label(['en']) ?? __('not set') }} @include('description._ric', ['term' => 'rico:hasCarrierType'])
        <br><strong>{{ __('Availability') }}:</strong> {{ $instantiation->availability->label(['en']) }}: {{ $instantiation->availability->labels->firstWhere('language', 'en')?->definition }}</p>
    @if($canUpdate)
        @include('description._actions', ['subject' => $instantiation, 'editRoute' => 'instantiations.edit', 'required' => 'instantiation-title'])
    @endif

    @include('description._viewer', ['instantiation' => $instantiation, 'files' => $instantiation->files->filter(fn ($f) => $deliverable[$f->id])->keyBy(fn ($f) => $f->use->notation),
        'title' => $instantiation->described('instantiation-title', ['en']), 'alt' => $instantiation->described('instantiation-alt-text', [app()->getLocale(), 'en']), 'fileRoute' => 'files.show'])
    <h2>{{ __('Files') }}</h2>
    @if($canUpdate && $instantiation->file('master'))
        <form method="post" action="{{ route('instantiations.remake-copies', $instantiation) }}">@csrf<button type="submit" style="margin-top: 0">{{ __('Make the viewing copies again') }}</button></form>
    @endif
    @forelse($instantiation->files->sortBy(fn ($f) => array_search($f->use->notation, ['master', 'reference', 'thumbnail'])) as $file)
        <section style="border-top: 1px solid var(--line); padding: .5rem 0">
            <h3 style="margin: .2rem 0">{{ $file->use->label(\App\Support\Vocab::languages()) }}</h3>
            <p>{{ $file->original_name }}<br>
                {{ $file->mime_type }} @include('description._ric', ['term' => 'openricx:hasMimeType']) · {{ \Illuminate\Support\Number::fileSize($file->size_bytes, 1) }} ({{ __(':count bytes', ['count' => number_format($file->size_bytes)]) }}) @include('description._ric', ['term' => 'rico:hasExtent'])
                @if($file->width) · {{ __(':width by :height pixels', ['width' => $file->width, 'height' => $file->height]) }} @endif
                <br>SHA-256 @include('description._ric', ['term' => 'openricx:hasChecksum']) <code style="word-break: break-all">{{ $file->sha256 }}</code>
                <br>@if($file->fixity_failed_at)<strong role="alert">{{ __('Fixity check failed on :date: the kept file is missing or changed. Restore it from a backup.', ['date' => $file->fixity_failed_at->format('Y-m-d H:i')]) }}</strong>
                    @elseif($file->fixity_checked_at){{ __('Fixity last checked :date: unchanged.', ['date' => $file->fixity_checked_at->format('Y-m-d H:i')]) }}@endif
                @if($file->use->notation === 'master')<br>{{ $file->scanned_with ? __('Scanned for viruses with :scanner on :date.', ['scanner' => $file->scanned_with, 'date' => $file->scanned_at->format('Y-m-d H:i')]) : __('Not scanned for viruses: no scanner was configured.') }}@endif</p>
            <p>@if($deliverable[$file->id])<a href="{{ route('files.show', $file) }}">{{ __('Open') }}</a> · <a href="{{ route('files.show', [$file, 'download' => 1]) }}">{{ __('Download') }}</a>@else<small>{{ __('You may not receive this file: its use needs the read-:use permission, and the instantiation is :availability.', ['use' => $file->use->notation, 'availability' => mb_strtolower($instantiation->availability->label(\App\Support\Vocab::languages()))]) }}</small>@endif
                @if($canUpdate) <form method="post" action="{{ route('files.fixity', $file) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Check fixity now') }}</button></form>@endif</p>
            @if($file->embedded_metadata && $deliverable[$file->id])
                <details><summary>{{ trans_choice('Embedded metadata: :count tag|Embedded metadata: :count tags', count($file->embedded_metadata), ['count' => count($file->embedded_metadata)]) }}</summary>
                    <table><thead><tr><th scope="col">{{ __('Tag') }}</th><th scope="col">{{ __('Value') }}</th></tr></thead><tbody>
                        @foreach($file->embedded_metadata as $tag => $value)<tr><td><code>{{ $tag }}</code></td><td style="word-break: break-word">{{ $value }}</td></tr>@endforeach
                    </tbody></table>
                    <p><small>{{ __('As the file gives it, read with exiftool. What a description can use was proposed for review.') }}</small></p>
                </details>
            @endif
        </section>
    @empty
        <p>{{ __('No file: a carrier recorded without one, such as the paper original.') }}</p>
    @endforelse

    @include('description._view', ['subject' => $instantiation, 'standard' => \App\Description\Elements::INSTANTIATION, 'drafts' => $canUpdate])
    @include('description._names', ['subject' => $instantiation, 'kind' => 'instantiations', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $instantiation, 'kind' => 'instantiations', 'canUpdate' => $canUpdate])
    @include('description._rights', ['subject' => $instantiation, 'kind' => 'instantiations', 'canUpdate' => $canUpdate])
    @include('description._community-labels', ['subject' => $instantiation, 'kind' => 'instantiations', 'canUpdate' => $canUpdate])
    @include('description._dates', ['subject' => $instantiation, 'kind' => 'instantiations', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])
    @if(auth()->user()->mayAnywhere('read', 'storage'))
        @include('description._relations', ['subject' => $instantiation, 'kind' => 'instantiations', 'relations' => $relations->filter(fn ($r) => auth()->user()->may('read', 'storage', $r->other)), 'canUpdate' => $canUpdate,
            'heading' => 'Physical storage', 'types' => ['storage'], 'section' => 'ps', 'add' => 'storage|source', 'addLabel' => 'Add a container it is kept in'])
    @endif
@endsection
