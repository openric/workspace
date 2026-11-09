{{-- OpenRiC Workspace - the signed-in account in the header: search, the clipboard, its own pages under its name, and log out. Shared by the staff and public layouts. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
    @auth
        <form method="post" action="{{ route('sign-out') }}">
            @csrf
            <a href="{{ route('search') }}">{{ __('Search') }}</a>
            <a href="{{ route('clipboard.index') }}">{{ __('Clipboard') }} ({{ app(\App\Clipboard\ClipboardService::class)->count() }})</a>
            @module('shop')<a href="{{ route('shop.cart') }}">{{ __('Cart') }} ({{ app(\App\Research\Shop::class)->count(auth()->user()) }})</a>@endmodule
            {{-- The account's own pages, under its name. --}}
            <details class="user-menu" name="main-nav">
                <summary @class(['here' => request()->routeIs('account.*', 'access-requests.*', 'jobs.*', 'favourites.*', 'research.*', 'shop.orders.mine')])>{{ auth()->user()->name }}</summary>
                <ul>
                    <li><a href="{{ route('account.profile') }}" @if(request()->routeIs('account.profile')) aria-current="page" @endif>{{ __('Profile') }}</a></li>
                    <li><a href="{{ route('jobs.index') }}" @if(request()->routeIs('jobs.*')) aria-current="page" @endif>{{ __('Jobs') }}</a></li>
                    @module('research')<li><a href="{{ route('research.profile') }}" @if(request()->routeIs('research.profile', 'research.book', 'research.bookings.*')) aria-current="page" @endif>{{ __('Researcher') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.reproductions.index') }}" @if(request()->routeIs('research.reproductions.*')) aria-current="page" @endif>{{ __('Reproductions') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.bibliographies.index') }}" @if(request()->routeIs('research.bibliographies.*')) aria-current="page" @endif>{{ __('Bibliographies') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.searches.index') }}" @if(request()->routeIs('research.searches.*')) aria-current="page" @endif>{{ __('Saved searches') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.collections.index') }}" @if(request()->routeIs('research.collections.*')) aria-current="page" @endif>{{ __('Collections') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.annotations.index') }}" @if(request()->routeIs('research.annotations.*')) aria-current="page" @endif>{{ __('My annotations') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.notebooks.index') }}" @if(request()->routeIs('research.notebooks.*')) aria-current="page" @endif>{{ __('Notebooks') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.diary.index') }}" @if(request()->routeIs('research.diary.*')) aria-current="page" @endif>{{ __('Research diary') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.workspaces.index') }}" @if(request()->routeIs('research.workspaces.*')) aria-current="page" @endif>{{ __('Research workspaces') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.projects.index') }}" @if(request()->routeIs('research.projects.*')) aria-current="page" @endif>{{ __('Research projects') }}</a></li>@endmodule
                    <li><a href="{{ route('clearance.request') }}" @if(request()->routeIs('clearance.request')) aria-current="page" @endif>{{ __('Access to a classified record') }}</a></li>
                    @module('research')<li><a href="{{ route('research.notifications') }}" @if(request()->routeIs('research.notifications*')) aria-current="page" @endif>{{ __('Notices') }}@php($unread = \App\Research\Notifications::unread(auth()->user()))@if($unread) ({{ $unread }})@endif</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.inbox') }}" @if(request()->routeIs('research.inbox*')) aria-current="page" @endif>{{ __('Inbox') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.teaching') }}" @if(request()->routeIs('research.teaching', 'research.lectures.*', 'research.courses.*')) aria-current="page" @endif>{{ __('Lectures and training') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.documents') }}" @if(request()->routeIs('research.documents*')) aria-current="page" @endif>{{ __('Research documents') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.calls') }}" @if(request()->routeIs('research.calls')) aria-current="page" @endif>{{ __('Funding calls') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.journals.index') }}" @if(request()->routeIs('research.journals.*')) aria-current="page" @endif>{{ __('Research journals') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.reports.index') }}" @if(request()->routeIs('research.reports.*')) aria-current="page" @endif>{{ __('Research reports') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.across') }}" @if(request()->routeIs('research.across')) aria-current="page" @endif>{{ __('Search across fonds') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.resolutions') }}" @if(request()->routeIs('research.resolutions')) aria-current="page" @endif>{{ __('Entity resolution') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.reasoning.memory') }}" @if(request()->routeIs('research.reasoning.memory')) aria-current="page" @endif>{{ __('Research memory') }}</a></li>@endmodule
                    @module('research')<li><a href="{{ route('research.submissions.index') }}" @if(request()->routeIs('research.submissions.index', 'research.submissions.show')) aria-current="page" @endif>{{ __('My submissions') }}</a></li>@endmodule
                    @module('shop')<li><a href="{{ route('shop.orders.mine') }}" @if(request()->routeIs('shop.orders.mine')) aria-current="page" @endif>{{ __('My orders') }}</a></li>@endmodule
                    <li><a href="{{ route('favourites.index') }}" @if(request()->routeIs('favourites.*')) aria-current="page" @endif>{{ __('Favourites') }}</a></li>
                    <li><a href="{{ route('access-requests.mine') }}" @if(request()->routeIs('access-requests.mine')) aria-current="page" @endif>{{ __('My access requests') }}</a></li>
                    @if(auth()->user()->mayAnywhere('review-access-requests', 'description'))<li><a href="{{ route('access-requests.index') }}" @if(request()->routeIs('access-requests.index')) aria-current="page" @endif>{{ __('Access requests') }}</a></li>@endif
                </ul>
            </details>
            <button type="submit">{{ __('Log out') }}</button>
        </form>
    @endauth
