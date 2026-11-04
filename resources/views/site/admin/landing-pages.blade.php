{{-- OpenRiC Workspace - the institution's landing pages, or a user's own dashboards (Heratio's landing page list and my dashboards; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $dashboard ? __('My dashboards') : __('Landing pages'))
@section('content')
    <h1>{{ $dashboard ? __('My dashboards') : __('Landing pages') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ $dashboard ? __('Pages of your own, built of blocks from what the catalogue publishes. Only you see them.') : __('Pages of the public site built of blocks: banners, text, searches, figures, collections, maps and links. The default page is the catalogue\'s home.') }}</p>
    <table><thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Address') }}</th><th scope="col">{{ __('Blocks') }}</th>@unless($dashboard)<th scope="col">{{ __('Shown') }}</th>@endunless</tr></thead>
        <tbody>@forelse($pages as $p)<tr><td><a href="{{ route('landing.edit', $p) }}">{{ $p->name }}</a>@if($p->is_default) <strong>({{ __('default') }})</strong>@endif</td>
            <td>@if($dashboard)<a href="{{ route('dashboards.show', $p) }}">{{ __('Open') }}</a>@elseif($p->is_active)<a href="{{ route('site.landing-page', $p->slug) }}">/landing/{{ $p->slug }}</a>@else /landing/{{ $p->slug }}@endif</td>
            <td>{{ $p->blocks_count }}</td>@unless($dashboard)<td>{{ $p->is_active ? __('Yes') : __('No') }}</td>@endunless</tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ $dashboard ? __('New dashboard') : __('New landing page') }}</h2>
    <form method="post" action="{{ $dashboard ? route('dashboards.store') : route('site.landing.store') }}">@csrf
        <label for="ln">{{ __('Name') }}</label><input id="ln" name="name" required maxlength="255">
        <label for="ls">{{ __('Address') }}</label><input id="ls" name="slug" maxlength="255" pattern="[a-z0-9]+(-[a-z0-9]+)*">
        <label for="ld">{{ __('Description') }}</label><input id="ld" name="description" maxlength="2000">
        @unless($dashboard)<label><input type="checkbox" name="is_default" value="1"> {{ __('The catalogue\'s home page') }}</label>@endunless
        <label><input type="checkbox" name="starter" value="1" checked> {{ __('Start with a banner, a search, figures, browse panels and recent items') }}</label>
        <button type="submit">{{ __('Create') }}</button>
    </form>
@endsection
