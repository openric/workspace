{{-- OpenRiC Workspace - an account's favourites and folders (roadmap phase 19a, ADR 0017), after Heratio's favourites browse (ahg-favorites). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Favourites'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Favourites') }}@if($current) : {{ $current->name }}@elseif($folder === 'unfiled') : {{ __('Unfiled') }}@endif</h1>
    @if(session('status'))<p role="status">{{ session('status') }}@if(session('share_link')) <code>{{ session('share_link') }}</code>@endif</p>@endif
    @error('favourites')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p><a href="{{ route('favourites.index') }}">{{ __('All') }} ({{ $total }})</a> · <a href="{{ route('favourites.index', ['folder' => 'unfiled']) }}">{{ __('Unfiled') }} ({{ $unfiled }})</a>
        @foreach($folders as $f) · <a href="{{ route('favourites.index', ['folder' => $f->id]) }}">{{ $f->name }} ({{ $f->favourites_count }})</a>@if($f->share_token_hash) <span class="meta">{{ $f->visibility->label($lang) }}</span>@endif @endforeach</p>

    <form method="get" action="{{ route('favourites.index') }}" class="filters">
        <input type="hidden" name="folder" value="{{ $folder }}">
        <label>{{ __('Search') }} <input name="q" value="{{ request('q') }}"></label>
        <label>{{ __('Sort by') }} <select name="sort">@foreach(['added' => 'Date added', 'title' => 'Title', 'reference' => 'Reference code'] as $k => $l)<option value="{{ $k }}" @selected(request('sort', 'added') === $k)>{{ __($l) }}</option>@endforeach</select></label>
        <button type="submit">{{ __('Show') }}</button>
    </form>

    @if($items->isEmpty())
        <p>{{ __('None yet. Add records and other entries from their pages.') }}</p>
    @else
        <form method="post" action="{{ route('favourites.bulk') }}" id="bulk">@csrf</form>
        <table>
            <thead><tr><th scope="col">{{ __('Choose') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Reference code') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Folder') }}</th><th scope="col">{{ __('Notes') }}</th><th scope="col">{{ __('Added') }}</th></tr></thead>
            <tbody>@foreach($items as $i)
                <tr>
                    <td><input type="checkbox" name="ids[]" value="{{ $i['favourite']->id }}" form="bulk" aria-label="{{ __('Choose :title', ['title' => $i['title']]) }}"></td>
                    <td>@if($i['url'])<a href="{{ $i['url'] }}">{{ $i['title'] }}</a>@else<span class="meta">{{ $i['title'] }}</span>@endif</td>
                    <td>{{ $i['reference'] }}</td><td>{{ $i['kind'] === 'link' ? __('Link') : ($i['entity'] ? \App\Relations\EntityKinds::noun($i['entity']) : '') }}</td>
                    <td>{{ $i['favourite']->folder?->name }}</td>
                    <td><form method="post" action="{{ route('favourites.notes', $i['favourite']) }}">@csrf<input name="notes" aria-label="{{ __('Notes') }}" value="{{ $i['favourite']->notes }}" size="24"> <button type="submit">{{ __('Save') }}</button></form></td>
                    <td>{{ $i['favourite']->created_at->format('Y-m-d') }}</td>
                </tr>
            @endforeach</tbody>
        </table>
        <p><label for="bulk-action">{{ __('With those chosen') }}</label>
            <select id="bulk-action" name="action" form="bulk"><option value="move">{{ __('Move to the folder') }}</option><option value="remove">{{ __('Remove') }}</option></select>
            <select name="folder_id" aria-label="{{ __('Folder') }}" form="bulk"><option value="">{{ __('Unfiled') }}</option>@foreach($folders as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
            <button type="submit" form="bulk">{{ __('Apply') }}</button></p>
        @php($bibliographies = \App\Models\Bibliography::where('user_id', auth()->id())->orderBy('name')->get())
        @if($bibliographies->isNotEmpty())
            <p><label for="to-bib">{{ __('Add the records chosen to the bibliography') }}</label> <select id="to-bib" name="bibliography_id" form="bulk">@foreach($bibliographies as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                <button type="submit" form="bulk" formaction="{{ route('research.bibliographies.from-favourites') }}">{{ __('Add') }}</button></p>
        @endif
        @php($collections = \App\Models\ResearchCollection::where('user_id', auth()->id())->orderBy('name')->get())
        @if($collections->isNotEmpty())
            <p><label for="to-coll">{{ __('Add what is chosen to the collection') }}</label> <select id="to-coll" name="collection_id" form="bulk">@foreach($collections as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                <button type="submit" form="bulk" formaction="{{ route('research.collections.from-favourites') }}">{{ __('Add') }}</button></p>
        @endif
        @php($projects = app(\App\Research\Projects::class)->contributing(auth()->user()))
        @if($projects->isNotEmpty())
            <p><label for="to-proj">{{ __('Add the records chosen to the project') }}</label> <select id="to-proj" name="project_id" form="bulk">@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach</select>
                <button type="submit" form="bulk" formaction="{{ route('research.projects.from-favourites') }}">{{ __('Add') }}</button></p>
        @endif
    @endif

    <h2>{{ __('Export') }}</h2>
    <p>@foreach(\App\Research\FavouritesExport::FORMATS as $format => $label)<a href="{{ route('favourites.export', [$format, 'folder' => $current?->id]) }}">{{ __($label) }}</a>@if(! $loop->last) · @endif @endforeach</p>

    @if($current)
        <h2>{{ __('This folder') }}</h2>
        @if($current->description)<p>{{ $current->description }}</p>@endif
        <p>{{ $current->visibility->label($lang) }}@if($current->share_expires_at) · {{ __('the link expires on :date', ['date' => $current->share_expires_at->format('Y-m-d')]) }}@endif
            @if($current->share_token_hash) · {{ trans_choice(':count visit|:count visits', $current->share_access_count, ['count' => $current->share_access_count]) }}@endif</p>
        <details><summary>{{ __('Share it by a link') }}</summary>
            <form method="post" action="{{ route('favourites.folders.share', $current) }}">@csrf
                <label for="share-days">{{ __('For this many days') }}</label> <input id="share-days" type="number" name="days" min="1" max="{{ \App\Research\Favourites::SHARE_DAYS_MAX }}" value="{{ \App\Research\Favourites::SHARE_DAYS }}" style="width: 5rem">
                <label><input type="checkbox" name="public" value="1"> {{ __('Public: the link does not expire') }}</label>
                <button type="submit">{{ $current->share_token_hash ? __('Make a new link') : __('Share') }}</button></form>
            @if($current->share_token_hash)<form method="post" action="{{ route('favourites.folders.unshare', $current) }}">@csrf<button type="submit">{{ __('Stop sharing') }}</button></form>@endif
        </details>
        <details><summary>{{ __('Rename or delete it') }}</summary>
            <form method="post" action="{{ route('favourites.folders.update', $current) }}">@csrf @include('favourites._folder-fields', ['f' => $current, 'p' => 'edit'])<button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('favourites.folders.delete', $current) }}">@csrf<button type="submit">{{ __('Delete the folder (its favourites are kept, unfiled)') }}</button></form>
        </details>
    @endif

    <h2>{{ __('More') }}</h2>
    <details><summary>{{ __('New folder') }}</summary>
        <form method="post" action="{{ route('favourites.folders.store') }}">@csrf @include('favourites._folder-fields', ['f' => null, 'p' => 'new'])<button type="submit">{{ __('Create') }}</button></form></details>
    <details><summary>{{ __('Add a link of your own') }}</summary>
        <form method="post" action="{{ route('favourites.links.store') }}">@csrf
            <p><label for="link-url">{{ __('Web address') }}</label> <input id="link-url" name="url" type="url" required size="50"> <label for="link-title">{{ __('Title') }}</label> <input id="link-title" name="title" required size="30">
                <input type="hidden" name="folder_id" value="{{ $current?->id }}"></p><button type="submit">{{ __('Add') }}</button></form></details>
    <details><summary>{{ __('Import records by their IRIs') }}</summary>
        <form method="post" action="{{ route('favourites.import') }}" enctype="multipart/form-data">@csrf
            <p><label for="imp-iris">{{ __('One persistent IRI a line') }}</label><br><textarea id="imp-iris" name="iris" rows="4" cols="60"></textarea></p>
            <p><label for="imp-file">{{ __('Or a file of them') }}</label> <input id="imp-file" type="file" name="file" accept=".csv,.txt"><input type="hidden" name="folder_id" value="{{ $current?->id }}"></p>
            <button type="submit">{{ __('Import') }}</button></form></details>
    @if($total > 0)
        <form method="post" action="{{ route('favourites.clear') }}" style="margin-top: 1rem">@csrf<button type="submit">{{ __('Clear all your favourites') }}</button></form>
    @endif
@endsection
