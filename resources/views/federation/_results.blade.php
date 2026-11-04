{{-- OpenRiC Workspace - what a federated search found, peer by peer and merged, each result with its source and whether its answer was verified (Heratio's provenance badge; roadmap phase 16a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($verification = \App\Models\Concept::choices(\App\Support\Vocab::BASE.'federation-verification/')->get()->keyBy('notation'))
<p class="meta">@foreach($found['peers'] as $p){{ $p['peer']->name }}: {{ trans_choice(':count result|:count results', $p['count'], ['count' => $p['count']]) }}@if($p['status'] !== 'success') ({{ $p['error'] ?? $p['status'] }})@endif @unless($loop->last) · @endunless @endforeach</p>
@forelse($found['results'] as $r)
    <article class="result">
        <h3>@if($r['url'] && preg_match('#^https?://#', $r['url']))<a href="{{ $r['url'] }}" rel="noopener noreferrer">{{ $r['title'] }}</a>@else{{ $r['title'] }}@endif</h3>
        <p class="meta">{{ $r['peer'] }}@if($r['referenceCode']) · {{ $r['referenceCode'] }}@endif @if($r['level']) · {{ $r['level'] }}@endif
            · {{ $r['verification'] ? $verification[$r['verification']]?->label($lang) : __('not verified') }}</p>
        @if($r['description'])<p>{{ \Illuminate\Support\Str::limit($r['description'], 300) }}</p>@endif
    </article>
@empty
    <p>{{ __('No peer found anything.') }}</p>
@endforelse
