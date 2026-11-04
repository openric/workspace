{{-- OpenRiC Workspace - the Help Center's categories, beside each of its pages (Heratio's help sidebar; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<nav aria-label="{{ __('Help categories') }}" class="meta" style="margin: 1rem 0">
    <a href="{{ route('help.index') }}">{{ __('Help Center') }}</a>
    @foreach($categories as $c) · <a href="{{ route('help.category', \App\Help\SystemMaps::slug($c->category)) }}" @if(($current ?? null) === $c->category) aria-current="page" @endif>{{ __($c->category) }}</a>@endforeach
    · <a href="{{ route('help.system-map') }}">{{ __('System map') }}</a> · <a href="{{ route('help.system-breakdown') }}">{{ __('System breakdown') }}</a>
</nav>
