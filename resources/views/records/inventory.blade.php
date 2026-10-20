{{-- OpenRiC Workspace - the units inside a unit, as a list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Inventory of '.$record->described('title', ['en']))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">Back to {{ $record->described('title', ['en']) }}</a></p>
    <h1>Inventory of {{ $record->described('title', ['en']) }}</h1>
    @if($rows === [])
        <p>No units inside it.</p>
    @else
        <table style="border-collapse: collapse; width: 100%">
            <thead><tr>@foreach(['Reference code', 'Title', 'Level', 'Dates', 'Extent'] as $h)<th style="text-align: left; border-bottom: 1px solid var(--line); padding: .3rem .5rem">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($rows as [$unit, $depth])
                @php($creation = $unit->dates->first(fn ($d) => $d->property->notation === 'creation'))
                <tr>
                    <td style="padding: .3rem .5rem; vertical-align: top">{{ $unit->referenceCode() }}</td>
                    <td style="padding: .3rem .5rem; padding-left: {{ .5 + $depth }}rem; vertical-align: top"><a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', [app()->getLocale(), 'en']) }}</a></td>
                    <td style="padding: .3rem .5rem; vertical-align: top">{{ $unit->level->label(['en', 'und']) }}</td>
                    <td style="padding: .3rem .5rem; vertical-align: top">{{ $creation?->display() ?? $unit->described('dates-expressed', ['en']) }}</td>
                    <td style="padding: .3rem .5rem; vertical-align: top">{{ $unit->described('extent', ['en']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
