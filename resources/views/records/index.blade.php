{{-- OpenRiC Workspace - browse archival descriptions. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Archival descriptions'))
@section('content')
    <h1>{{ __('Archival descriptions') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>@if(auth()->user()->mayAnywhere('create', 'description'))<a href="{{ route('records.create') }}">{{ __('New top-level unit (fonds or collection)') }}</a> · @endif<a href="{{ route('records.export-all') }}">{{ __('Export as CSV') }}</a>@if(auth()->user()->mayAnywhere('create', 'description')) · <a href="{{ route('imports.create') }}">{{ __('Import') }}</a> · <a href="{{ route('imports.index') }}">{{ __('Import') }}</a>@endif</p>
    <form method="get" action="{{ route('records.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Title, other name or reference code') }}</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="repository" style="margin-top: 0">{{ __('Repository') }}</label>
            <select id="repository" name="repository" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($repositories as $r)<option value="{{ $r->id }}" @selected($filters['repository'] === $r->id)>{{ $r->described('authorised-name', ['en']) }}</option>@endforeach
            </select></div>
        <div><label for="level" style="margin-top: 0">{{ __('Level') }}</label>
            <select id="level" name="level" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($levels as $l)<option value="{{ $l->id }}" @selected($filters['level'] === $l->id)>{{ $l->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <div><label for="publication" style="margin-top: 0">{{ __('Publication') }}</label>
            <select id="publication" name="publication" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                <option value="published" @selected($filters['publication'] === 'published')>{{ __('Title published') }}</option>
                <option value="unpublished" @selected($filters['publication'] === 'unpublished')>{{ __('Title not published') }}</option>
            </select></div>
        <div><label for="sort" style="margin-top: 0">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" style="font: inherit; padding: .5rem">
                @foreach($sorts as $o)<option value="{{ $o->notation }}" @selected($filters['sort'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <div><label for="direction" style="margin-top: 0">{{ __('Direction') }}</label>
            <select id="direction" name="direction" style="font: inherit; padding: .5rem">
                @foreach($directions as $o)<option value="{{ $o->notation }}" @selected($filters['direction'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <div><label for="view" style="margin-top: 0">{{ __('Show as') }}</label>
            <select id="view" name="view" style="font: inherit; padding: .5rem">
                @foreach($views as $o)<option value="{{ $o->notation }}" @selected($filters['view'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <div><input type="hidden" name="top" value="0"><label style="margin-top: 0"><input type="checkbox" name="top" value="1" style="width: auto" @checked($filters['top'])> {{ __('Top-level only') }}</label></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    @php($lang = [app()->getLocale(), 'en'])
    @if($units->isEmpty())
        <p>{{ __($query !== '' ? 'No unit of description matches.' : 'No archival descriptions yet.') }}</p>
    @else
        <p class="meta">{{ __(':count found.', ['count' => $units->total()]) }} <a href="{{ request()->fullUrlWithQuery(['format' => 'print', 'page' => null]) }}">{{ __('Print this list') }}</a> · <a href="{{ request()->fullUrlWithQuery(['format' => 'csv', 'page' => null]) }}">{{ __('Download this list as CSV') }}</a></p>
        @php($meta = fn ($unit) => collect([$unit->level->label(\App\Support\Vocab::languages()), $unit->referenceCode(), $unit->described('dates-expressed', $lang) ?: $unit->dates->map->display()->filter()->join('; '), $unit->described('extent', $lang)])->filter()->join(' · '))
        @if($filters['view'] === 'table')
            <table><thead><tr>@if($thumbnails->isNotEmpty())<th scope="col">{{ __('Image') }}</th>@endif<th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Reference code') }}</th><th scope="col">{{ __('Level') }}</th><th scope="col">{{ __('Dates') }}</th><th scope="col">{{ __('Repository') }}</th></tr></thead><tbody>
                @foreach($units as $unit)<tr>@if($thumbnails->isNotEmpty())<td>@if($file = $thumbnails[$unit->id] ?? null)<img src="{{ route('files.show', $file) }}" alt="" loading="lazy" style="width: 64px; height: 48px; object-fit: cover">@endif</td>@endif
                    <td><a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', $lang) }}</a></td><td>{{ $unit->referenceCode() }}</td><td>{{ $unit->level->label(\App\Support\Vocab::languages()) }}</td>
                    <td>{{ $unit->described('dates-expressed', $lang) ?: $unit->dates->map->display()->filter()->join('; ') }}</td><td>{{ $unit->repository?->described('authorised-name', $lang) }}</td></tr>@endforeach
            </tbody></table>
        @elseif($filters['view'] === 'cards')
            <ul style="display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: .8rem; list-style: none; padding: 0">
                @foreach($units as $unit)<li style="margin: 0; padding: .6rem; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface)">
                    @if($file = $thumbnails[$unit->id] ?? null)<img src="{{ route('files.show', $file) }}" alt="" loading="lazy" style="width: 100%; height: 8rem; object-fit: cover; border-radius: 6px">@endif
                    <a href="{{ route('records.show', $unit) }}"><strong>{{ $unit->described('title', $lang) }}</strong></a><br><span class="meta">{{ $meta($unit) }}</span></li>@endforeach
            </ul>
        @else
        <ul>
            @foreach($units as $unit)
                <li><a href="{{ route('records.show', $unit) }}"><strong>{{ $unit->described('title', $lang) }}</strong></a>
                    @include('description._row-actions', ['entity' => $unit])
                    <span class="meta">{{ $meta($unit) }}</span>
                    <span class="meta">{{ collect([$unit->repository?->described('authorised-name', $lang), $unit->parent ? __('Part of :title', ['title' => $unit->parent->described('title', $lang)]) : null,
                        $unit->children_count ? trans_choice(':count lower level|:count lower levels', $unit->children_count) : null, $unit->instantiations_count ? trans_choice(':count file|:count files', $unit->instantiations_count) : null])->filter()->join(' · ') }}</span>
                    @if($scope = $unit->described('scope-and-content', $lang))<span class="summary">{{ \Illuminate\Support\Str::limit(strip_tags($scope), 260) }}</span>@endif</li>
            @endforeach
        </ul>
        @endif
    @endif
    @if($units->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($units->previousPageUrl())<a href="{{ $units->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $units->currentPage(), 'pages' => $units->lastPage()]) }}
            @if($units->nextPageUrl())<a href="{{ $units->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
    {{-- How this account browses (Heratio's user display preference; phase 20d). --}}
    @if($settings['may_override'])
        <details><summary>{{ __('How I browse') }}</summary>
            <form method="post" action="{{ route('records.browse-settings') }}">@csrf
                <label for="bs-view">{{ __('Show as') }}</label><select id="bs-view" name="view">@foreach($views as $o)<option value="{{ $o->notation }}" @selected($settings['view'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
                <label for="bs-sort">{{ __('Sort by') }}</label><select id="bs-sort" name="sort">@foreach($sorts as $o)<option value="{{ $o->notation }}" @selected($settings['sort'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
                <label for="bs-direction">{{ __('Direction') }}</label><select id="bs-direction" name="direction">@foreach($directions as $o)<option value="{{ $o->notation }}" @selected($settings['direction'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
                <label for="bs-per-page">{{ __('Units a page') }}</label><input id="bs-per-page" name="per_page" type="number" min="10" max="200" value="{{ $settings['per_page'] }}">
                <label><input type="hidden" name="show_thumbnails" value="0"><input type="checkbox" name="show_thumbnails" value="1" style="width: auto" @checked($settings['show_thumbnails'])> {{ __('Show images in the table and the cards') }}</label>
                <button type="submit">{{ __('Save as how I browse') }}</button>
                @if($settings['own'])<button type="submit" name="reset" value="1">{{ __('Browse as the institution does') }}</button>@endif
            </form></details>
    @endif
@endsection
