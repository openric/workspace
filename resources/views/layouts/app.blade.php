{{-- OpenRiC Workspace - page layout. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ \App\Models\Institution::current()?->headerTitle() ?? config('app.name') }}</title>
    @include('layouts._style')
    @stack('head')
</head>
<body class="staff">
{{-- WCAG 2.4.1: past the header and the menu to the page's own content, as the public layout already does. --}}
<a class="skip" href="#content">{{ __('Skip to the content') }}</a>
{{-- One row (phase 26): the institution and the way home, the menus, a search, then the account's tools. --}}
<header class="site-header">
    @php $brand = \App\Models\Institution::current(); @endphp
    <a class="brand" href="{{ route('home') }}">
        @if($brand?->logo_path)<img src="{{ route('branding.logo') }}?v={{ md5($brand->logo_path) }}" alt="" style="max-height: 40px; max-width: 150px">@endif
        <strong>{{ $brand?->headerTitle() ?? config('app.name') }}</strong>
        @unless(str_contains($brand?->headerTitle() ?? config('app.name'), 'Workspace'))<span class="product">Workspace</span>@endunless
    </a>
    @include('layouts._staff-nav')
    <div class="header-tools">
        @auth
            <form method="get" action="{{ route('search') }}" role="search" class="header-search">
                <label for="staff-q" class="visually-hidden">{{ __('Search') }}</label>
                <input id="staff-q" name="q" type="search" value="{{ request()->routeIs('search') ? request('q') : '' }}" placeholder="{{ __('Search') }}">
            </form>
        @endauth
        @include('layouts._account')
        @guest @include('layouts._language') @endguest
    </div>
</header>
<main id="content" tabindex="-1">
    @yield('content')
</main>
@include('layouts._footer')
</body>
</html>
