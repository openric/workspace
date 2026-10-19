{{-- OpenRiC Workspace - page layout. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name') }}</title>
    <style>
        :root { --ink: #1f2933; --muted: #52606d; --line: #cbd2d9; --accent: #10373e; --bg: #ffffff; --error: #9b1c1c; }
        @media (prefers-color-scheme: dark) { :root { --ink: #e4e7eb; --muted: #9aa5b1; --line: #3e4c59; --accent: #7fc4cf; --bg: #111a1d; --error: #f8b4b4; } }
        body { font: 16px/1.5 system-ui, sans-serif; color: var(--ink); background: var(--bg); margin: 0; }
        header, main { max-width: 60rem; margin: 0 auto; padding: 1rem; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); }
        a { color: var(--accent); }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input { font: inherit; padding: .5rem; width: 100%; max-width: 24rem; box-sizing: border-box; border: 1px solid var(--line); background: var(--bg); color: var(--ink); }
        button { font: inherit; margin-top: 1rem; padding: .5rem 1rem; background: var(--accent); color: var(--bg); border: 0; cursor: pointer; }
        .error { color: var(--error); }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: .4rem; border-bottom: 1px solid var(--line); }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
    </style>
</head>
<body>
<header>
    <strong>{{ config('app.name') }}</strong>
    @auth
        <form method="post" action="{{ route('sign-out') }}">
            @csrf
            <span>{{ auth()->user()->name }}</span>
            <button type="submit">Sign out</button>
        </form>
    @endauth
</header>
<main>
    @yield('content')
</main>
</body>
</html>
