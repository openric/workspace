{{-- OpenRiC Workspace - the relations of a public page that stand, each to the page of the entity at the other end. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $relations (DiscoverController::relations), $view; optional $heading, $types (relation-type notations to show) or $except. --}}
@php($shown = $relations->filter(fn ($r) => isset($types) ? in_array($r->relationType->notation, $types, true) : ! in_array($r->relationType->notation, $except ?? [], true)))
@if($shown->isNotEmpty())
    <h2>{{ $heading ?? 'Related' }}</h2>
    <ul>
        @foreach($shown as $relation)
            @php($url = \App\Discovery\Links::page($relation->other))
            @php($from = $relation->datedAs('beginning')?->display())
            @php($to = $relation->datedAs('end')?->display())
            <li>{{ ucfirst($relation->phrase()) }}
                @if($url)<a href="{{ $url }}">{{ $view->label($relation->other) }}</a>@else{{ $view->label($relation->other) }}@endif
                @if($relation->role) <span class="meta">as {{ $relation->role->label(['en', 'und']) }}</span>@endif
                @if($from || $to) <span class="meta">({{ $from ?? '?' }} to {{ $to ?? '?' }})</span>@endif
                @if($relation->standing() === 'disputed') <span class="meta">(disputed)</span>@endif
                @include('description._ric', ['term' => $relation->ricTerm()])</li>
        @endforeach
    </ul>
@endif
