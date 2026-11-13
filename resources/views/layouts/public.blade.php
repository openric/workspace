{{-- OpenRiC Workspace - the public catalogue's page layout (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($institution ??= \App\Support\HostInstitution::for(request()) ?? abort(404))
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ $institution->headerTitle() }}</title>
    @include('layouts._style')
    <style>
        .skip { position: absolute; left: -999px; } .skip:focus { left: 1rem; top: .5rem; background: var(--bg); padding: .5rem; }
        .meta { color: var(--muted); font-size: .9rem; }
        dl.values dt { font-weight: 600; margin-top: .8rem; } dl.values dd { margin: .2rem 0 0; }
        /* The catalogue's home page: openric.org's hero and plain-language lede. */
        .catalogue-hero { margin: 0 0 1.5rem; padding: 2.5rem 2.5rem 2.25rem; border: 1px solid var(--line); border-radius: 12px;
            background: radial-gradient(ellipse 60% 80% at 15% 0%, rgba(31, 95, 168, .08), transparent 60%), radial-gradient(ellipse 50% 60% at 85% 100%, rgba(16, 185, 129, .07), transparent 60%), linear-gradient(to bottom, var(--surface) 0%, var(--bg) 100%); }
        .catalogue-hero h1 { font-size: clamp(1.6rem, 3vw, 2.2rem); letter-spacing: -.02em; }
        .catalogue-lede { font-size: .95rem; color: var(--muted-2); text-align: justify; hyphens: auto; }
        .catalogue-counts { color: var(--muted); font-size: .88rem; }
        .catalogue-search div { display: flex; gap: .5rem; max-width: 34rem; } .catalogue-search input { max-width: none; flex: 1; } .catalogue-search button { margin: 0; }
.imageflow { margin: 1rem 0; padding: .6rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); }
        .imageflow ul { display: flex; gap: .6rem; overflow-x: auto; list-style: none; margin: 0; padding: 0 0 .3rem; scrollbar-width: thin; }
        .imageflow li { flex: none; width: 128px; margin: 0; }
        .imageflow a { display: block; text-decoration: none !important; color: var(--muted-2); font-size: .8rem; text-align: center; }
        .imageflow img { width: 128px; height: 96px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); display: block; margin-bottom: .25rem; }
        .imageflow a:hover img, .imageflow a:focus-visible img { border-color: var(--link); }
        .catalogue-credits { text-align: justify; margin-top: 3rem; padding: 1.1rem 1.3rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); font-size: .92rem; color: var(--muted-2); }
        .catalogue-credits h2 { margin-top: 0; font-size: 1.05rem; border: 0; padding: 0; }
        @media (max-width: 40rem) { .catalogue-hero { padding: 1.5rem 1.1rem; border-radius: var(--radius); } .catalogue-lede { text-align: left; } }
    </style>
    @hasSection('head')@yield('head')@endif
</head>
<body>
<a class="skip" href="#content">{{ __('Skip to the content') }}</a>
{{-- The institution's own band (phase 26): its colour (Settings), its name, the search, then the visitor's links. --}}
<header class="public-band" style="--band: {{ $institution->brand_colour ?? '#0f3e75' }}">
    <a href="{{ route('discover.home') }}" class="brand">
        @if($institution->logo_path)<img src="{{ route('branding.logo') }}?v={{ md5($institution->logo_path) }}" alt="" style="max-height: 48px; max-width: 160px">@endif
        <span><strong class="title">{{ $institution->headerTitle() }}</strong><span class="band-sub">{{ __('The catalogue') }}</span></span>
    </a>
    <form method="get" action="{{ route('discover.home') }}" role="search" class="band-search">
        <label for="site-q" class="visually-hidden">{{ __('Search the catalogue') }}</label>
        <input id="site-q" name="q" type="search" value="{{ request('q') }}" placeholder="{{ __('Search the catalogue') }}">
        <button type="submit">{{ __('Search') }}</button>
    </form>
    <div class="band-links">
        {{-- The catalogue's assistant, where the institution offers it (phase 17f). --}}
        @if($institution && app(\App\Ai\Gateway::class)->available($institution->id) && app(\App\Ai\Gateway::class)->settings($institution->id)->chatbot_public)<a href="{{ route('discover.ask') }}">{{ __('Ask the catalogue') }}</a>@endif
        @guest<a href="{{ route('clipboard.index') }}">{{ __('Clipboard') }} ({{ app(\App\Clipboard\ClipboardService::class)->count() }})</a>@module('shop')<a href="{{ route('shop.cart') }}">{{ __('Cart') }} ({{ app(\App\Research\Shop::class)->count(null) }})</a>@endmodule<a href="{{ route('sign-in') }}">{{ __('Log in') }}</a>@endguest
        @include('layouts._account')
        @include('layouts._language')
    </div>
</header>
@include('layouts._staff-nav')
<main id="content">
    @yield('content')
</main>
@include('layouts._footer', ['institution' => $institution])
</body>
</html>
