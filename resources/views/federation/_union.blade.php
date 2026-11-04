{{-- OpenRiC Workspace - union catalogue results, each with its member (Heratio's union search; roadmap phase 16b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p role="status">{{ trans_choice(':count found.|:count found.', $found['total'], ['count' => $found['total']]) }}</p>
@foreach($found['results'] as $r)
    <article class="result"><h3>@if($r['url'])<a href="{{ $r['url'] }}" rel="noopener noreferrer">{{ $r['title'] }}</a>@else{{ $r['title'] }}@endif</h3>
        <p class="meta">{{ $r['source'] }}@if($r['repository']) · {{ $r['repository'] }}@endif @if($r['level']) · {{ $r['level'] }}@endif @if($r['dates']) · {{ $r['dates'] }}@endif</p></article>
@endforeach
