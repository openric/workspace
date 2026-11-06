{{-- OpenRiC Workspace - the verified links a discovery rests on, by domain (roadmap phase 17d4). Needs $evidence. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@foreach($evidence['connections'] ?? [] as $group)
    <p><strong>{{ __($group['domain']) }}</strong> ({{ $group['count'] }}): @foreach($group['items'] as $item)@if($item['url'])<a href="{{ $item['url'] }}">{{ $item['name'] }}</a>@else{{ $item['name'] }}@endif{{ $loop->last ? '' : '; ' }}@endforeach</p>
@endforeach
@if(! empty($evidence['second_hop']))<p><strong>{{ __('Reached through them') }}</strong>: @foreach($evidence['second_hop'] as $c){{ $c['name'] }} <small>({{ __('via :name', ['name' => $c['via']]) }})</small>{{ $loop->last ? '' : '; ' }}@endforeach</p>@endif
