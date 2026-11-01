{{-- OpenRiC Workspace - a bibliography (Heratio's viewBibliography; roadmap phase 19c): its entries in its style, adding, importing, exporting and sharing. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $bibliography->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('research.bibliographies.index') }}">{{ __('Bibliographies') }}</a></p>
    <h1>{{ $bibliography->name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}@if(session('share_link')) <code>{{ session('share_link') }}</code>@endif</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($bibliography->description)<p>{{ $bibliography->description }}</p>@endif
    <p>@include('research._project-select', ['thing' => $bibliography, 'kind' => 'bibliography'])</p>
    <p>{{ $bibliography->citationStyle->label($lang) }} · {{ __('Export') }}: @foreach(\App\Research\Bibliographies::EXPORTS as $f => $l)<a href="{{ route('research.bibliographies.export', [$bibliography, $f]) }}">{{ __($l) }}</a>@if(! $loop->last), @endif @endforeach</p>
    @forelse($bibliography->entries as $e)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p>{{ $service->styled($e, $bibliography->citationStyle->notation) }} <span class="meta">{{ $e->entryType->label($lang) }}</span>@if($e->record) · <a href="{{ route('records.show', $e->record) }}">{{ __('the record') }}</a>@endif</p>
            <details><summary>{{ __('Change') }}</summary>
                <form method="post" action="{{ route('research.bibliographies.entry', [$e, 'update']) }}">@csrf @include('research._entry-fields', ['e' => $e, 'p' => 'e'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.bibliographies.entry', [$e, 'remove']) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>
            </details>
        </section>
    @empty<p>{{ __('No entry yet.') }}</p>@endforelse
    <h2>{{ __('Add') }}</h2>
    <form method="post" action="{{ route('research.bibliographies.step', [$bibliography, 'record']) }}">@csrf<label for="bb-iri">{{ __('A record of the catalogue (its persistent IRI)') }}</label> <input id="bb-iri" name="iri" required size="50"> <button type="submit">{{ __('Add') }}</button></form>
    <details><summary>{{ __('Another work') }}</summary><form method="post" action="{{ route('research.bibliographies.step', [$bibliography, 'entry']) }}">@csrf @include('research._entry-fields', ['e' => null, 'p' => 'new'])<button type="submit">{{ __('Add') }}</button></form></details>
    <details><summary>{{ __('Import') }}</summary>
        <form method="post" action="{{ route('research.bibliographies.step', [$bibliography, 'import']) }}">@csrf
            <label for="bb-format">{{ __('Format') }}</label> <select id="bb-format" name="format"><option value="bibtex">BibTeX</option><option value="ris">RIS</option><option value="citation">{{ __('A citation as written') }}</option></select><br>
            <textarea name="text" rows="6" cols="80" required aria-label="{{ __('The text to import') }}"></textarea><br><button type="submit">{{ __('Import') }}</button></form>
    </details>
    <h2>{{ __('Settings') }}</h2>
    <form method="post" action="{{ route('research.bibliographies.update', $bibliography) }}">@csrf
        <label for="bs-name">{{ __('Name') }}</label> <input id="bs-name" name="name" required value="{{ $bibliography->name }}">
        <label for="bs-style">{{ __('Citation style') }}</label> <select id="bs-style" name="citation_style">@foreach($styles as $s)<option value="{{ $s->notation }}" @selected($bibliography->citationStyle->notation === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select>
        <label for="bs-desc">{{ __('Description') }}</label> <input id="bs-desc" name="description" size="40" value="{{ $bibliography->description }}"> <button type="submit">{{ __('Save') }}</button></form>
    <form method="post" action="{{ route('research.bibliographies.step', [$bibliography, 'share']) }}">@csrf<input type="hidden" name="share" value="{{ $bibliography->is_public ? 0 : 1 }}"><button type="submit">{{ $bibliography->is_public ? __('Stop sharing') : __('Share it by a link') }}</button></form>
    <form method="post" action="{{ route('research.bibliographies.step', [$bibliography, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the bibliography') }}</button></form>
@endsection
