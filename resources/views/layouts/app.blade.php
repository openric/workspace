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
    <a href="{{ route('discover.home') }}" style="color: inherit; text-decoration: none; display: flex; align-items: center; gap: .75rem">
        @if($brand?->logo_path)<img src="{{ route('branding.logo') }}?v={{ md5($brand->logo_path) }}" alt="" style="max-height: 48px; max-width: 160px">@endif
        <strong>{{ $brand?->headerTitle() ?? config('app.name') }}</strong>
        @unless(str_contains($brand?->headerTitle() ?? config('app.name'), 'Workspace'))<span class="product">Workspace</span>@endunless
    </a>
    @auth
        <form method="post" action="{{ route('sign-out') }}">
            @csrf
            <a href="{{ route('clipboard.index') }}">{{ __('Clipboard') }} ({{ app(\App\Clipboard\ClipboardService::class)->count() }})</a>
            <a href="{{ route('account.profile') }}">{{ auth()->user()->name }}</a>
            <button type="submit">{{ __('Log out') }}</button>
        </form>
    @endauth
    @include('layouts._language')
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
                'records.index' => [__('Archival descriptions'), $user->mayAnywhere('read', 'description'), 'records.*|!records.export-all'],
                'agents.index' => [__('Authority records'), $user->mayAnywhere('read', 'authority'), 'agents.*|!agents.duplicates'],
                'activities.index' => [__('Activities'), $user->mayAnywhere('read', 'authority'), 'activities.*'],
                'places.index' => [__('Places'), true, 'places.*'],
                'repositories.index' => [__('Repositories'), $user->mayAnywhere('read', 'repository'), 'repositories.*'],
            ],
            'Manage' => [
                'vocabularies.index' => [__('Taxonomies'), true, 'vocabularies.*'],
                'containers.index' => [__('Containers'), $user->mayAnywhere('read', 'storage'), 'containers.*'],
                'storage.places' => [__('Physical storage'), $user->mayAnywhere('read', 'storage'), 'storage.*'],
                'imports.index' => [__('Import'), $mayImport, 'imports.*'],
                'proposals.index' => [__('Proposals to review'), $user->mayAnywhere('review-proposals', 'description'), 'proposals.*'],
                'records.export-all' => [__('Export descriptions'), $user->mayAnywhere('read', 'description'), 'records.export-all'],
                'agents.duplicates' => [__('Duplicate authority records'), $user->may('update', 'authority'), 'agents.duplicates'],
            ],
            'Admin' => [
                'accounts.index' => [__('Users'), $may('manage-accounts'), 'accounts.*'],
                'roles.index' => [__('Roles'), $may('manage-roles'), 'roles.*'],
                'audit' => [__('Audit log'), $may('view-audit-log'), 'audit'],
                'settings.edit' => [__('Settings'), $may('manage-institution'), 'settings.*'],
            ],
        ]));
        // An icon beside each main item, drawn here (24 by 24, stroked); the word stays, so the icon is hidden from screen readers.
        $icons = [
            'Home' => '<path d="M3 11l9-8 9 8"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
            'Describe' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h5"/>',
            'Manage' => '<path d="M3 4h18v5H3z"/><path d="M5 9v11h14V9"/><path d="M10 13h4"/>',
            'Admin' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
            'Public catalogue' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3.5 3.5 3.5 14.5 0 18M12 3c-3.5 3.5-3.5 14.5 0 18"/>',
        ];
        $icon = fn (string $item) => new \Illuminate\Support\HtmlString('<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'.$icons[$item].'</svg>');
        $current = function (array $item): bool {
            $patterns = collect(explode('|', $item[2]));
            $not = $patterns->filter(fn ($p) => str_starts_with($p, '!'))->map(fn ($p) => substr($p, 1));

            return $patterns->reject(fn ($p) => str_starts_with($p, '!'))->contains(fn ($p) => request()->routeIs($p))
                && ! $not->contains(fn ($p) => request()->routeIs($p));
        };
    @endphp
    <nav aria-label="Main" class="main-nav">
        <ul>
            <li><a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>{{ $icon('Home') }}{{ __('Home') }}</a></li>
            @foreach($nav as $group => $items)
                @php($open = collect($items)->contains($current))
                <li><details name="main-nav">
                    <summary @class(['here' => $open])>{{ $icon($group) }}{{ __($group) }}</summary>
                    <ul>
                        @foreach($items as $name => $item)
                            <li><a href="{{ route($name) }}" @if($current($item)) aria-current="page" @endif>{{ $item[0] }}</a></li>
                        @endforeach
                    </ul>
                </details></li>
            @endforeach
            <li><a href="{{ route('discover.home') }}">{{ $icon('Public catalogue') }}{{ __('Public catalogue') }}</a></li>
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
