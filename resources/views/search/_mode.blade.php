{{-- OpenRiC Workspace - search by words, by meaning or both, and the words a search was widened with (roadmap phase 17d1). Needs $institutionId, $search (or null) and $query; inside the search form. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@if(app(\App\Search\Meaning::class)->available($institutionId))
    @php($mode = $search['mode'] ?? request()->query('mode', 'words'))
    <label for="search-mode" style="margin: 0">{{ __('Find') }}</label>
    <select id="search-mode" name="mode" style="margin: 0">
        <option value="words" @selected($mode === 'words')>{{ __('by the words') }}</option>
        <option value="meaning" @selected($mode === 'meaning')>{{ __('by meaning') }}</option>
        <option value="both" @selected($mode === 'both')>{{ __('by the words and by meaning') }}</option>
    </select>
@endif
