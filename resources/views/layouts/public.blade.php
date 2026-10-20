{{-- OpenRiC Workspace - the public catalogue's page layout (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ $institution->headerTitle() }}</title>
    @include('layouts._style')
    <style>
        .skip { position: absolute; left: -999px; } .skip:focus { left: 1rem; top: .5rem; background: var(--bg); padding: .5rem; }
        .meta { color: var(--muted); font-size: .9rem; }
        dl.values dt { font-weight: 600; margin-top: .8rem; } dl.values dd { margin: .2rem 0 0; }
    </style>
    @hasSection('head')@yield('head')@endif
</head>
<body>
<a class="skip" href="#content">Skip to the content</a>
<header>
    <a href="{{ route('discover.home') }}" style="color: inherit; text-decoration: none; display: flex; align-items: center; gap: .75rem">
        @if($institution->logo_path)<img src="{{ route('branding.logo') }}?v={{ md5($institution->logo_path) }}" alt="" style="max-height: 48px; max-width: 160px">@endif
        <strong>{{ $institution->headerTitle() }}</strong>
    </a>
    <form method="get" action="{{ route('discover.home') }}" role="search" style="display: flex; gap: .5rem; align-items: center">
        <label for="site-q" style="margin: 0; position: absolute; left: -999px">Search the catalogue</label>
        <input id="site-q" name="q" value="{{ request('q') }}" placeholder="Search the catalogue" style="max-width: 16rem">
        <button type="submit" style="margin: 0">Search</button>
        @auth<a href="{{ route('home') }}">Workspace</a>@else<a href="{{ route('sign-in') }}">Sign in</a>@endauth
    </form>
</header>
<main id="content">
    @yield('content')
</main>
<footer style="max-width: 60rem; margin: 2rem auto; padding: 1rem; border-top: 1px solid var(--line)" class="meta">
    Described in RiC (Records in Contexts). The data is open through the <a href="{{ route('api.service') }}">OpenRiC API</a> and <a href="{{ route('api.oai') }}?verb=Identify">OAI-PMH</a>.
</footer>
</body>
</html>
