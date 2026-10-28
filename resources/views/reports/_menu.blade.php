{{-- OpenRiC Workspace - the menu of reports (roadmap phase 8; Heratio's _reports-menu, ahg-reports). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($titles = \App\Http\Controllers\ReportController::TITLES)
<nav aria-label="{{ __('Reports') }}"><p>
    <a href="{{ route('reports.index') }}" @if(request()->routeIs('reports.index')) aria-current="page" @endif>{{ __('Overview') }}</a>
    · <a href="{{ route('reports.quality') }}" @if(request()->routeIs('reports.quality')) aria-current="page" @endif>{{ __('Data quality') }}</a>
    · <a href="{{ route('reports.usage') }}" @if(request()->routeIs('reports.usage')) aria-current="page" @endif>{{ __('Usage statistics') }}</a>
    @foreach($lists as $item)
        · <a href="{{ route('reports.'.$item) }}" @if(request()->routeIs('reports.'.$item)) aria-current="page" @endif>{{ __($titles[$item]) }}</a>
    @endforeach
</p></nav>
