{{-- OpenRiC Workspace - the public catalogue's page layout (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
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
        .catalogue-hero h1 { font-size: clamp(2rem, 4vw, 2.9rem); letter-spacing: -.028em; }
        .catalogue-lede { font-size: 1.1rem; color: var(--muted-2); text-align: justify; hyphens: auto; }
        .catalogue-counts { color: var(--muted); font-size: .95rem; }
        .catalogue-search div { display: flex; gap: .5rem; max-width: 34rem; } .catalogue-search input { max-width: none; flex: 1; } .catalogue-search button { margin: 0; }
.imageflow { margin: 1rem 0; padding: .6rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); }
        .imageflow ul { display: flex; gap: .6rem; overflow-x: auto; list-style: none; margin: 0; padding: 0 0 .3rem; scrollbar-width: thin; }
        .imageflow li { flex: none; width: 128px; margin: 0; }
        .imageflow a { display: block; text-decoration: none !important; color: var(--muted-2); font-size: .8rem; text-align: center; }
        .imageflow img { width: 128px; height: 96px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); display: block; margin-bottom: .25rem; }
        .imageflow a:hover img, .imageflow a:focus-visible img { border-color: var(--link); }
        .catalogue-credits { text-align: justify; margin-top: 3rem; padding: 1.1rem 1.3rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); font-size: .92rem; color: var(--muted-2); }
        .catalogue-credits h2 { margin-top: 0; font-size: 1.05rem; border: 0; padding: 0; }
        .search-layout { display: grid; grid-template-columns: 15rem 1fr; gap: 1.5rem; align-items: start; }
        .search-facets h2 { font-size: 1rem; margin: 1rem 0 .3rem; border: 0; padding: 0; }
        .search-facets .facets { list-style: none; padding: 0; margin: 0; font-size: .92rem; }
        .search-facets .facets li { margin: .2rem 0; }
        .facet-years input { max-width: 6rem; }
        .snippet { display: block; font-size: .9rem; color: var(--muted-2); margin-top: .25rem; }
        .snippet mark { background: #fef08a; color: #1f2937; padding: 0 .1rem; border-radius: 2px; }
        @media (prefers-color-scheme: dark) { .snippet mark { background: #a16207; color: #fff; } }
        @media (max-width: 52rem) { .search-layout { grid-template-columns: 1fr; } .search-facets { order: 2; } }
        @media (max-width: 40rem) { .catalogue-hero { padding: 1.5rem 1.1rem; border-radius: var(--radius); } .catalogue-lede { text-align: left; } }
    </style>
    @hasSection('head')@yield('head')@endif
</head>
<body>
<a class="skip" href="#content">{{ __('Skip to the content') }}</a>
<header>
    <a href="{{ route('discover.home') }}" style="color: inherit; text-decoration: none; display: flex; align-items: center; gap: .75rem">
        @if($institution->logo_path)<img src="{{ route('branding.logo') }}?v={{ md5($institution->logo_path) }}" alt="" style="max-height: 48px; max-width: 160px">@endif
        <strong>{{ $institution->headerTitle() }}</strong>
    </a>
    <form method="get" action="{{ route('discover.home') }}" role="search" style="display: flex; gap: .5rem; align-items: center">
        <label for="site-q" style="margin: 0; position: absolute; left: -999px">{{ __('Search the catalogue') }}</label>
        <input id="site-q" name="q" value="{{ request('q') }}" placeholder="{{ __('Search the catalogue') }}" style="max-width: 16rem">
        <button type="submit" style="margin: 0">{{ __('Search') }}</button>
        <a href="{{ route('clipboard.index') }}" style="white-space: nowrap">{{ __('Clipboard') }} ({{ app(\App\Clipboard\ClipboardService::class)->count() }})</a>
        @auth<a href="{{ route('home') }}">Workspace</a>@else<a href="{{ route('sign-in') }}" style="white-space: nowrap">{{ __('Log in') }}</a>@endauth
    </form>
    @include('layouts._language')
</header>
<main id="content">
    @yield('content')
</main>
<footer style="max-width: 60rem; margin: 2rem auto; padding: 1rem; border-top: 1px solid var(--line)" class="meta">
    {!! __('Described in RiC (Records in Contexts). The data is open through the :api and :oai.', ['api' => '<a href="'.e(route('api.service')).'">'.e(__('OpenRiC API')).'</a>', 'oai' => '<a href="'.e(route('api.oai')).'?verb=Identify">OAI-PMH</a>']) !!}
</footer>
</body>
</html>
