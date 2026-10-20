{{-- OpenRiC Workspace - the hierarchy a unit belongs to. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Hierarchy of '.$top->described('title', ['en']))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">Back to {{ $record->described('title', ['en']) }}</a></p>
    <h1>Hierarchy of {{ $top->described('title', ['en']) }}</h1>
    <p>The arrangement (rico:hasOrHadPart), in order. Move units, or change their order, from each unit's page.</p>
    @php
        $line = fn ($u) => (($u->id === $record->id) ? '<strong aria-current="page">' : '').'<a href="'.e(route('records.show', $u)).'">'.e($u->described('title', [app()->getLocale(), 'en'])).'</a>'.(($u->id === $record->id) ? '</strong>' : '')
            .' <small>'.e(collect([$u->level->label(['en', 'und']), $u->identifier])->filter()->join(', ')).'</small>';
        $branch = function ($parentId) use (&$branch, $byParent, $line) {
            $out = '';
            foreach ($byParent[$parentId] ?? [] as $unit) {
                $out .= '<li>'.$line($unit).(isset($byParent[$unit->id]) ? '<ul>'.$branch($unit->id).'</ul>' : '').'</li>';
            }

            return $out;
        };
    @endphp
    <ul><li>{!! $line($top) !!}@if(isset($byParent[$top->id]))<ul>{!! $branch($top->id) !!}</ul>@endif</li></ul>
@endsection
