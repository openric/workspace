{{-- OpenRiC Workspace - move one item of a list up or down, by posting the list in its new order (roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $ids (the list's ids in order), $i (this item's place) and $action (where the order is put). --}}
@foreach([-1 => __('Up'), 1 => __('Down')] as $step => $word)
    @if(isset($ids[$i + $step]))
        @php($order = $ids)
        @php([$order[$i], $order[$i + $step]] = [$order[$i + $step], $order[$i]])
        <form method="post" action="{{ $action }}" style="display: inline">@csrf @method('PUT')@foreach($order as $id)<input type="hidden" name="order[]" value="{{ $id }}">@endforeach<button type="submit" style="margin: 0; padding: .1rem .5rem" aria-label="{{ $word }}">{{ $step < 0 ? '↑' : '↓' }}</button></form>
    @endif
@endforeach
