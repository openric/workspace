{{-- OpenRiC Workspace - the hierarchy a unit belongs to, depth first, a page of it at a time (#8). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Hierarchy of :name', ['name' => $top->described('title', ['en'])]))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back to :name', ['name' => $record->described('title', ['en'])]) }}</a></p>
    <h1>{{ __('Hierarchy of :name', ['name' => $top->described('title', ['en'])]) }}</h1>
    <p>{{ __('The arrangement (rico:hasOrHadPart), in order. Move units, or change their order, from each unit\'s page.') }}</p>
    @php
        $levels = []; // each level's label once, not once a line
        $line = function ($u, $title, $code) use (&$levels, $record) {
            $meta = collect([$levels[$u->level_id] ??= $u->level?->label(\App\Support\Vocab::languages()), $code])->filter()->join(', ');

            return ($u->id === $record->id ? '<strong aria-current="page">' : '').'<a href="'.e(route('records.show', $u)).'">'.e($title ?: '[Untitled]').'</a>'
                .($u->id === $record->id ? '</strong>' : '').' <small>'.e($meta).'</small>';
        };
    @endphp
    <p>{!! $line($top, $top->described('title', [app()->getLocale(), 'en']), $top->identifier) !!}</p>
    {{-- Depth first: each unit indented by its depth below the top, the level of description saying what it is. --}}
    <ul class="outline" style="list-style: none; padding-left: 0">
        @foreach($units as ['unit' => $unit, 'depth' => $depth])
            <li style="margin-left: {{ $depth * 1.25 }}rem">{!! $line($unit, $titles[$unit->id] ?? null, $codes[$unit->id] ?? null) !!}</li>
        @endforeach
    </ul>
    @if($page > 1 || $more)
        <nav aria-label="{{ __('Hierarchy of :name', ['name' => $top->described('title', ['en'])]) }}"><p>
            @if($page > 1)<a href="{{ route('records.tree', [$record, 'page' => $page - 1]) }}" rel="prev">{{ __('Previous page') }}</a>@endif
            @if($page > 1 && $more) · @endif
            @if($more)<a href="{{ route('records.tree', [$record, 'page' => $page + 1]) }}" rel="next">{{ __('Next page') }}</a>@endif
        </p></nav>
    @endif
@endsection
