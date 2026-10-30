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
<body>
<header>
    @php $brand = \App\Models\Institution::current(); @endphp
    <a href="{{ route('discover.home') }}" style="color: inherit; text-decoration: none; display: flex; align-items: center; gap: .75rem">
        @if($brand?->logo_path)<img src="{{ route('branding.logo') }}?v={{ md5($brand->logo_path) }}" alt="" style="max-height: 48px; max-width: 160px">@endif
        <strong>{{ $brand?->headerTitle() ?? config('app.name') }}</strong>
        @unless(str_contains($brand?->headerTitle() ?? config('app.name'), 'Workspace'))<span class="product">Workspace</span>@endunless
    </a>
    @include('layouts._account')
    @include('layouts._language')
</header>
@include('layouts._staff-nav')
<main>
    @yield('content')
</main>
</body>
</html>
