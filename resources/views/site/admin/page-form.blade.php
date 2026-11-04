{{-- OpenRiC Workspace - a static page in one language (Heratio's static page edit; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $page ? __('Edit page') : __('New page'))
@section('content')
    <h1>{{ $page ? __('Edit page') : __('New page') }}</h1>
    <p><a href="{{ route('site.pages') }}">{{ __('Static pages') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    @if($page)
        <form method="get" action="{{ route('site.pages.edit', $page) }}"><label for="pl">{{ __('Language') }}</label>
            <input id="pl" name="language" value="{{ $language }}" size="8" pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8})*"> <button type="submit">{{ __('Write in this language') }}</button>
            <span class="meta">{{ __('Written in: :languages', ['languages' => $page->translations->pluck('language')->join(', ')]) }}</span></form>
    @endif
    <form method="post" action="{{ $page ? route('site.pages.update', $page) : route('site.pages.store') }}">@csrf @if($page)@method('PUT')@endif
        <input type="hidden" name="language" value="{{ $language }}">
        <label for="ps">{{ __('Address') }}</label><input id="ps" name="slug" required value="{{ old('slug', $slug) }}" pattern="[a-z0-9]+(-[a-z0-9]+)*">
        <p class="meta">{{ __('Lower-case letters, digits and hyphens. about, privacy and terms are served at /about, /privacy and /terms; any other at /pages/ and its address.') }}</p>
        <label for="pt">{{ __('Title') }} ({{ $language }})</label><input id="pt" name="title" required maxlength="1024" value="{{ old('title', $text?->title) }}">
        <label for="pc">{{ __('Text') }} ({{ __('Markdown') }})</label><textarea id="pc" name="content" rows="18">{{ old('content', $text?->content) }}</textarea>
        <button type="submit">{{ __('Save') }}</button>
    </form>
    @if($page)<form method="post" action="{{ route('site.pages.destroy', $page) }}" onsubmit="return confirm(@json(__('Delete this page in every language?')))">@csrf @method('DELETE')<button type="submit">{{ __('Delete page') }}</button></form>@endif
@endsection
