{{-- OpenRiC Workspace - building a landing page or dashboard block by block (Heratio's landing page editor; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Edit: :name', ['name' => $page->name]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Edit: :name', ['name' => $page->name]) }}</h1>
    <p>@if($page->owner_id)<a href="{{ route('dashboards') }}">{{ __('My dashboards') }}</a> · <a href="{{ route('dashboards.show', $page) }}">{{ __('Open') }}</a>
        @else<a href="{{ route('site.landing') }}">{{ __('Landing pages') }}</a>@if($page->is_active) · <a href="{{ route('site.landing-page', $page->slug) }}">{{ __('On the public site') }}</a>@endif @endif</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif

    <details><summary>{{ __('Page settings') }}</summary>
        <form method="post" action="{{ route('landing.update', $page) }}">@csrf @method('PUT')
            <label for="pn">{{ __('Name') }}</label><input id="pn" name="name" required maxlength="255" value="{{ $page->name }}">
            <label for="ps">{{ __('Address') }}</label><input id="ps" name="slug" maxlength="255" value="{{ $page->slug }}">
            <label for="pd">{{ __('Description') }}</label><input id="pd" name="description" maxlength="2000" value="{{ $page->description }}">
            @unless($page->owner_id)
                <label><input type="checkbox" name="is_active" value="1" @checked($page->is_active)> {{ __('Shown on the public site') }}</label>
                <label><input type="checkbox" name="is_default" value="1" @checked($page->is_default)> {{ __('The catalogue\'s home page') }}</label>
                <label for="pmt">{{ __('Title for search engines') }}</label><input id="pmt" name="meta_title" maxlength="255" value="{{ $page->meta_title }}">
                <label for="pmd">{{ __('Description for search engines') }}</label><textarea id="pmd" name="meta_description" rows="2">{{ $page->meta_description }}</textarea>
            @else<input type="hidden" name="is_active" value="1">@endunless
            <label for="pc">{{ __('Style classes') }}</label><input id="pc" name="css_classes" maxlength="255" value="{{ $page->css_classes }}">
            <label for="pcss">{{ __('Style sheet of its own (CSS)') }}</label><textarea id="pcss" name="custom_css" rows="4">{{ $page->custom_css }}</textarea>
            <button type="submit">{{ __('Save') }}</button></form>
        <form method="post" action="{{ route('landing.destroy', $page) }}" onsubmit="return confirm(@json(__('Delete this page and its blocks?')))">@csrf @method('DELETE')<button type="submit">{{ __('Delete page') }}</button></form>
    </details>

    <h2>{{ __('Blocks') }}</h2>
    @php($ids = $blocks->pluck('id')->all())
    @forelse($blocks as $i => $block)
        @include('site.admin._block-editor', ['block' => $block, 'ids' => $ids, 'i' => $i])
    @empty<p>{{ __('No blocks yet.') }}</p>@endforelse

    <h2>{{ __('Add a block') }}</h2>
    <form method="post" action="{{ route('landing.blocks.store', $page) }}">@csrf<label for="bt">{{ __('Kind of block') }}</label>
        <select id="bt" name="type">@foreach($types as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Add') }}</button></form>
@endsection
