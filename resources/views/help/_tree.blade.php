{{-- OpenRiC Workspace - one level of the system map or breakdown, each node opening onto the next (roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $nodes and $depth. --}}
<ul style="list-style: none; padding-left: {{ $depth ? '1.3rem' : 0 }}; @if($depth) border-left: 2px solid var(--line); @endif">
    @foreach($nodes as $node)
        <li style="margin: .35rem 0">
            @php($label = $node['help'] ? '<a href="'.e(route('help.article', $node['help'])).'">'.e(__($node['label'])).'</a>' : e(__($node['label'])))
            @if($node['children'])
                <details @if($depth === 0) open @endif><summary><strong>{!! $label !!}</strong>@if(! empty($node['sub'])) <span class="meta">{{ __($node['sub']) }}</span>@endif</summary>
                    @include('help._tree', ['nodes' => $node['children'], 'depth' => $depth + 1])</details>
            @else
                {!! $label !!}@if(! empty($node['sub'])) <span class="meta">{{ __($node['sub']) }}</span>@endif
            @endif
        </li>
    @endforeach
</ul>
