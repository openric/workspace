{{-- OpenRiC Workspace - the words a search was widened with (roadmap phase 17d1), and whether it shows close matches or a regular expression's (25a). Needs $search. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@if(! empty($search['expanded']))<p class="meta">{{ __('Also searched for: :terms.', ['terms' => implode(', ', $search['expanded'])]) }}</p>@endif
@if(($search['mode'] ?? 'words') !== 'words')<p class="meta">{{ $search['mode'] === 'meaning' ? __('Found by meaning: the nearest first, whether or not they hold the words.') : __('Found by the words and by meaning, the two put together.') }}</p>@endif
@if(! empty($search['error']))<p class="meta" role="alert">{{ $search['error'] }}</p>@endif
@if(! empty($search['close']))<p class="meta">{{ __('Nothing matched every word, so these are the closest matches by title. Check the spelling, or search for fewer words.') }}</p>@endif
@if(($search['match'] ?? 'words') === 'pattern' && empty($search['error']))<p class="meta">{{ __('Searched with a regular expression, without regard to case.') }}</p>@endif
