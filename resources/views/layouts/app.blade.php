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
    @php $brand = \App\Models\Institution::current(); @endphp
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
    @php
        $user = auth()->user();
        $may = fn (string $permission) => $user->can(\App\Support\Vocab::permission($permission));
        $mayImport = collect(array_keys(\App\Exchange\Import\ImportService::FORMATS))
            ->contains(fn ($format) => $user->mayAnywhere('create', \App\Exchange\Import\ImportService::permission($format)));
        // group => [route name => [label, shown, the routes it is current for, "!" before one it is not]]; a group with nothing shown is left out.
        // As AtoM: what is described, then the working tools (its Manage), then administration (its Admin).
        $nav = array_filter(array_map(fn ($items) => array_filter($items, fn ($item) => $item[1]), [
            'Describe' => [
                'records.index' => ['Archival descriptions', $user->mayAnywhere('read', 'description'), 'records.*|!records.export-all'],
                'agents.index' => ['Authority records', $user->mayAnywhere('read', 'authority'), 'agents.*|!agents.duplicates'],
                'activities.index' => ['Activities', $user->mayAnywhere('read', 'authority'), 'activities.*'],
                'places.index' => ['Places', true, 'places.*'],
                'repositories.index' => ['Repositories', $user->mayAnywhere('read', 'repository'), 'repositories.*'],
            ],
            'Manage' => [
                'vocabularies.index' => ['Vocabularies', true, 'vocabularies.*'],
                'containers.index' => ['Containers', $user->mayAnywhere('read', 'storage'), 'containers.*'],
                'storage.places' => ['Storage places', $user->mayAnywhere('read', 'storage'), 'storage.*'],
                'imports.index' => ['Imports', $mayImport, 'imports.*'],
                'records.export-all' => ['Export descriptions', $user->mayAnywhere('read', 'description'), 'records.export-all'],
                'agents.duplicates' => ['Duplicate authority records', $user->may('update', 'authority'), 'agents.duplicates'],
            ],
            'Admin' => [
                'accounts.index' => ['Accounts', $may('manage-accounts'), 'accounts.*'],
                'roles.index' => ['Roles', $may('manage-roles'), 'roles.*'],
                'audit' => ['Audit log', $may('view-audit-log'), 'audit'],
                'settings.edit' => ['Settings', $may('manage-institution'), 'settings.*'],
            ],
        ]));
        $current = function (array $item): bool {
            $patterns = collect(explode('|', $item[2]));
            $not = $patterns->filter(fn ($p) => str_starts_with($p, '!'))->map(fn ($p) => substr($p, 1));

            return $patterns->reject(fn ($p) => str_starts_with($p, '!'))->contains(fn ($p) => request()->routeIs($p))
                && ! $not->contains(fn ($p) => request()->routeIs($p));
        };
    @endphp
    <nav aria-label="Main" class="main-nav">
        <ul>
            <li><a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Home</a></li>
            @foreach($nav as $group => $items)
                @php($open = collect($items)->contains($current))
                <li><details name="main-nav">
                    <summary @class(['here' => $open])>{{ $group }}</summary>
                    <ul>
                        @foreach($items as $name => $item)
                            <li><a href="{{ route($name) }}" @if($current($item)) aria-current="page" @endif>{{ $item[0] }}</a></li>
                        @endforeach
                    </ul>
                </details></li>
            @endforeach
            <li><a href="{{ route('discover.home') }}">Public catalogue</a></li>
        </ul>
    </nav>
    <script>
        // Close an open menu on Escape or a click outside it; the menus work without this.
        document.addEventListener('click', e => document.querySelectorAll('.main-nav details[open]').forEach(d => d.contains(e.target) || d.removeAttribute('open')));
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const d = document.querySelector('.main-nav details[open]');
            if (d) { d.removeAttribute('open'); d.querySelector('summary').focus(); }
        });
    </script>
@endauth
<main>
    @yield('content')
</main>
</body>
</html>
