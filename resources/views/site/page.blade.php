{{-- OpenRiC Workspace - one of the institution's static pages, in the visitor's language where it has one (Heratio's static page; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $text->title)
@section('content')
    <article class="site-text">
        <h1>{{ $text->title }}</h1>
        {!! $html !!}
        @if(auth()->user()?->mayAnywhere('publish', 'description'))<p class="meta"><a href="{{ route('site.pages.edit', [$page, 'language' => $text->language]) }}">{{ __('Edit this page') }}</a></p>@endif
    </article>
@endsection
