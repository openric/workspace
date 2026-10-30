{{-- OpenRiC Workspace - the signed-in account in the header: search, the clipboard, its own pages under its name, and log out. Shared by the staff and public layouts. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
    @auth
        <form method="post" action="{{ route('sign-out') }}">
            @csrf
            <a href="{{ route('search') }}">{{ __('Search') }}</a>
            <a href="{{ route('clipboard.index') }}">{{ __('Clipboard') }} ({{ app(\App\Clipboard\ClipboardService::class)->count() }})</a>
            {{-- The account's own pages, under its name. --}}
            <details class="user-menu" name="main-nav">
                <summary @class(['here' => request()->routeIs('account.*', 'access-requests.*', 'jobs.*')])>{{ auth()->user()->name }}</summary>
                <ul>
                    <li><a href="{{ route('account.profile') }}" @if(request()->routeIs('account.profile')) aria-current="page" @endif>{{ __('Profile') }}</a></li>
                    <li><a href="{{ route('jobs.index') }}" @if(request()->routeIs('jobs.*')) aria-current="page" @endif>{{ __('Jobs') }}</a></li>
                    <li><a href="{{ route('access-requests.mine') }}" @if(request()->routeIs('access-requests.mine')) aria-current="page" @endif>{{ __('My access requests') }}</a></li>
                    @if(auth()->user()->mayAnywhere('review-access-requests', 'description'))<li><a href="{{ route('access-requests.index') }}" @if(request()->routeIs('access-requests.index')) aria-current="page" @endif>{{ __('Access requests') }}</a></li>@endif
                </ul>
            </details>
            <button type="submit">{{ __('Log out') }}</button>
        </form>
    @endauth
