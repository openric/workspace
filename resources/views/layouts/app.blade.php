{{-- OpenRiC Workspace - page layout. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ \App\Models\Institution::current()?->headerTitle() ?? config('app.name') }}</title>
    @include('layouts._style')
</head>
<body>
<header>
    @php($brand = \App\Models\Institution::current())
    <a href="{{ auth()->check() ? route('home') : route('sign-in') }}" style="color: inherit; text-decoration: none; display: flex; align-items: center; gap: .75rem">
        @if($brand?->logo_path)<img src="{{ route('branding.logo') }}?v={{ md5($brand->logo_path) }}" alt="" style="max-height: 48px; max-width: 160px">@endif
        <strong>{{ $brand?->headerTitle() ?? config('app.name') }}</strong>
    </a>
    @auth
        <form method="post" action="{{ route('sign-out') }}">
            @csrf
            <span>{{ auth()->user()->name }}</span>
            <button type="submit">Sign out</button>
        </form>
    @endauth
</header>
@auth
    @php($nav = array_filter([
        'home' => ['Home', true],
        'repositories.index' => ['Repositories', auth()->user()->mayAnywhere('read', 'repository')],
        'records.index' => ['Archival descriptions', auth()->user()->mayAnywhere('read', 'description')],
        'agents.index' => ['Authority records', auth()->user()->mayAnywhere('read', 'authority')],
        'activities.index' => ['Activities', auth()->user()->mayAnywhere('read', 'authority')],
        'places.index' => ['Places', true],
        'vocabularies.index' => ['Vocabularies', true],
        'accounts.index' => ['Accounts', auth()->user()->can(\App\Support\Vocab::permission('manage-accounts'))],
        'roles.index' => ['Roles', auth()->user()->can(\App\Support\Vocab::permission('manage-roles'))],
        'audit' => ['Audit log', auth()->user()->can(\App\Support\Vocab::permission('view-audit-log'))],
        'settings.edit' => ['Settings', auth()->user()->can(\App\Support\Vocab::permission('manage-institution'))],
    ], fn ($item) => $item[1]))
    <nav aria-label="Main" style="max-width: 60rem; margin: 0 auto; padding: .5rem 1rem; display: flex; gap: 1.25rem; flex-wrap: wrap; border-bottom: 1px solid var(--line)">
        @foreach($nav as $name => [$label])
            @php($here = request()->routeIs($name === 'home' ? 'home' : explode('.', $name)[0].'*'))
            <a href="{{ route($name) }}" @if($here) aria-current="page" style="font-weight: 700" @endif>{{ $label }}</a>
        @endforeach
    </nav>
@endauth
<main>
    @yield('content')
</main>
</body>
</html>
