{{-- OpenRiC Workspace - the Help Center's search box (roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<form method="get" action="{{ route('help.search') }}" role="search" class="catalogue-search"><label for="help-q">{{ __('Search the help') }}</label>
    <div><input id="help-q" name="q" required value="{{ $query ?? '' }}"><button type="submit">{{ __('Search') }}</button></div></form>
