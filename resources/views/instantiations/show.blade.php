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
    @if(! $canUpdate && $instantiation->availability->notation === 'on-request')
        @php($accessRequests = app(\App\AccessRequests\AccessRequestService::class))
        @if($open = $accessRequests->open(auth()->user(), $instantiation))
            <p><a href="{{ route('access-requests.show', $open) }}">{{ $open->grantsAccess() ? __('Your access to these files lasts until :date.', ['date' => $open->expires_at->format('Y-m-d')]) : __('Your request for these files waits for review.') }}</a></p>
        @elseif($accessRequests->grants(auth()->user(), $instantiation))
            <p>{{ __('An approved access request opens these files.') }}</p>
        @elseif($accessRequests->mayRequest(auth()->user(), $instantiation))
            <p><a href="{{ route('access-requests.create', $instantiation) }}">{{ __('Ask for access to these files') }}</a></p>
        @endif
    @endif
    @if($canUpdate && $instantiation->file('master'))
        <form method="post" action="{{ route('instantiations.remake-copies', $instantiation) }}">@csrf<button type="submit" style="margin-top: 0">{{ __('Make the viewing copies again') }}</button></form>
        {{-- Phase 17a: a vision model reads the page through the AI gateway; the text waits for review as a proposal. --}}
        @if(\App\Jobs\ReadPageText::image($instantiation) && app(\App\Ai\Gateway::class)->available(auth()->user()->institution_id))
            <form method="post" action="{{ route('instantiations.read-text', $instantiation) }}">@csrf<button type="submit" style="margin-top: 0">{{ __('Read the text with AI') }}</button>
                <small>{{ __('A vision model reads the page through the AI gateway, checked against Tesseract. Its text is a proposal you review.') }}</small></form>
            <form method="post" action="{{ route('instantiations.assess-condition', $instantiation) }}">@csrf<button type="submit" style="margin-top: 0">{{ __('Assess its condition with AI') }}</button>
                <small>{{ __('A vision model looks at the picture and proposes its physical characteristics: a condition grade and the damage it sees, for you to check against the object.') }}</small></form>
        @endif
        <form method="post" action="{{ route('instantiations.replace-copy', $instantiation) }}" enctype="multipart/form-data">
            @csrf
            <label for="own-copy-use">{{ __('Give your own copy') }}</label>
            <small id="own-copy-help">{{ __('An image used instead of the copy made from the master. Making the viewing copies again replaces it.') }}</small>
            <select id="own-copy-use" name="use" aria-describedby="own-copy-help"><option value="reference">{{ __('Reference copy') }}</option><option value="thumbnail">{{ __('Thumbnail') }}</option></select>
            <input type="file" name="file" accept="image/*" required aria-label="{{ __('Image') }}">
            <button type="submit">{{ __('Replace the copy') }}</button>
        </form>
    @endif
    @forelse($instantiation->files->sortBy(fn ($f) => array_search($f->use->notation, ['master', 'reference', 'thumbnail'])) as $file)
        <section style="border-top: 1px solid var(--line); padding: .5rem 0">
            <h3 style="margin: .2rem 0">{{ $file->use->label(\App\Support\Vocab::languages()) }}</h3>
            <p>{{ $file->original_name }}<br>
                {{ $file->mime_type }} @include('description._ric', ['term' => 'openricx:hasMimeType']) · {{ \Illuminate\Support\Number::fileSize($file->size_bytes, 1) }} ({{ __(':count bytes', ['count' => number_format($file->size_bytes)]) }}) @include('description._ric', ['term' => 'rico:hasExtent'])
                @if($file->width) · {{ __(':width by :height pixels', ['width' => $file->width, 'height' => $file->height]) }} @endif
                <br>SHA-256 @include('description._ric', ['term' => 'openricx:hasChecksum']) <code style="word-break: break-all">{{ $file->sha256 }}</code>
                <br>@if($file->fixity_failed_at)<strong role="alert">{{ __('Fixity check failed on :date: the kept file is missing or changed. Restore it from a backup.', ['date' => $file->fixity_failed_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) }}</strong>
                    @elseif($file->fixity_checked_at){{ __('Fixity last checked :date: unchanged.', ['date' => $file->fixity_checked_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) }}@endif
                @if($file->use->notation === 'master')<br>{{ $file->scanned_with ? __('Scanned for viruses with :scanner on :date.', ['scanner' => $file->scanned_with, 'date' => $file->scanned_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) : __('Not scanned for viruses: no scanner was configured.') }}@endif</p>
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

    @if($instantiation->files->isNotEmpty())
        {{-- What was done to its files, as PREMIS events, and each file's PRONOM format (phase 15a). --}}
        <h2 id="preservation">{{ __('Preservation') }}</h2>
        @foreach($instantiation->files->sortBy(fn ($f) => array_search($f->use->notation, ['master', 'preservation', 'access', 'reference', 'thumbnail'])) as $file)
            <p><strong>{{ $file->use->label(\App\Support\Vocab::languages()) }}</strong>:
                @if($file->format)<a href="{{ $file->format->pronomUrl() }}" rel="noopener noreferrer">{{ $file->format->puid }}</a> {{ $file->format->name }}@if($file->format->risk) · {{ __('risk :risk', ['risk' => mb_strtolower($file->format->risk->label(\App\Support\Vocab::languages()))]) }}@endif
                @elseif($file->format_identified_at){{ __('format not identified') }}@if($file->format_warning) ({{ $file->format_warning }})@endif
                @else{{ __('format not yet identified') }}@endif</p>
        @endforeach
        @php($events = \App\Models\PreservationEvent::whereIn('digital_file_id', $instantiation->files->pluck('id'))->with('type', 'outcome')->orderByDesc('occurred_at')->limit(50)->get())
        <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('File') }}</th><th scope="col">{{ __('Outcome') }}</th><th scope="col">{{ __('By') }}</th></tr></thead>
            <tbody>@forelse($events as $e)<tr><td>{{ $e->occurred_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</td><td>{{ $e->type->label(\App\Support\Vocab::languages()) }}@if($e->detail)<br><small>{{ $e->detail }}</small>@endif</td>
                <td>{{ $e->file_label }}</td><td>{{ $e->outcome->label(\App\Support\Vocab::languages()) }}@if($e->outcome_detail)<br><small style="word-break: break-all">{{ \Illuminate\Support\Str::limit($e->outcome_detail, 120) }}</small>@endif</td><td>{{ $e->agent_name }}</td></tr>
            @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
        <p><a href="{{ route('records.export.download', [$unit, 'premis']) }}">{{ __('Download the PREMIS 3.0 preservation metadata of the unit') }}</a></p>
        @if(auth()->user()?->hasPermission(\App\Support\Vocab::permission('manage-institution')) && $instantiation->file('master'))
            {{-- A preservation master and access copy by the normalisation rules (phase 15c). --}}
            <form method="post" action="{{ route('preservation.normalise', $instantiation) }}">@csrf<button type="submit">{{ __('Normalise the master by the rules') }}</button></form>
        @endif
    @endif

    @if($instantiation->files->contains(fn ($f) => str_starts_with($f->mime_type, 'audio/') || str_starts_with($f->mime_type, 'video/')))
        <h2 id="captions">{{ __('Captions') }}</h2>
        @error('captions')<p class="error" role="alert">{{ $message }}</p>@enderror
        @forelse($instantiation->captionTracks as $track)
            <p>{{ $track->label }} ({{ $track->language }}, {{ __($track->kind) }}) · {{ trans_choice(':count cue|:count cues', $track->cue_count, ['count' => $track->cue_count]) }}@if($track->is_default) · {{ __('shown by default') }}@endif
                · <a href="{{ route('captions.show', $track) }}">{{ __('Open') }}</a>
                @if($canUpdate)<form method="post" action="{{ route('captions.destroy', $track) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Remove') }}</button></form>@endif</p>
        @empty
            <p>{{ __('No caption tracks yet.') }}</p>
        @endforelse
        @if($canUpdate)
            <form method="post" action="{{ route('captions.store', $instantiation) }}" enctype="multipart/form-data">
                @csrf
                <label for="caption-file">{{ __('WebVTT file') }}</label>
                <small id="caption-help">{{ __('A .vtt file of at most 2 MB. A track of the same language and kind replaces the one there.') }}</small>
                <input type="file" id="caption-file" name="file" accept=".vtt,text/vtt" required aria-describedby="caption-help">
                <label for="caption-language">{{ __('Language') }}</label>
                <input type="text" id="caption-language" name="language" required maxlength="35" value="{{ old('language', 'en') }}">
                <label for="caption-kind">{{ __('Kind') }}</label>
                <select id="caption-kind" name="kind">@foreach(\App\Media\Captions::KINDS as $kind)<option value="{{ $kind }}">{{ __($kind) }}</option>@endforeach</select>
                <label for="caption-label">{{ __('Label') }}</label>
                <input type="text" id="caption-label" name="label" maxlength="200" value="{{ old('label') }}">
                <label><input type="checkbox" name="is_default" value="1"> {{ __('Shown by default') }}</label>
                <button type="submit">{{ __('Add the caption track') }}</button>
            </form>
        @endif
    @endif

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
