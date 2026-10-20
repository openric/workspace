{{-- OpenRiC Workspace - a container's box list, to print and keep in it. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Box list: '.$container->described('container-name', ['en']))
@section('content')
    <p class="no-print"><a href="{{ route('containers.show', $container) }}">Back to the container</a> · <a href="#" onclick="window.print(); return false">Print</a></p>
    <style>@media print { .no-print, nav, header, footer { display: none !important } }</style>
    <h1>{{ $container->described('container-name', ['en']) }}</h1>
    <p>{{ collect([$container->containerType?->anyLabel(['en']), $container->identifier, $path])->filter()->join(', ') }}. {{ $units->count() }} {{ $units->count() === 1 ? 'unit' : 'units' }} of description, listed {{ now()->format('j F Y') }}.</p>
    @if($units->isEmpty())
        <p>It holds no unit of description.</p>
    @else
        <table>
            <thead><tr><th>Reference code</th><th>Title</th><th>Dates</th><th>Conditions of access</th><th>Part of</th></tr></thead>
            <tbody>
            @foreach($units as $unit)
                <tr><td>{{ $unit->referenceCode() }}</td>
                    <td>{{ $unit->described('title', ['en']) }} ({{ mb_strtolower((string) $unit->level->label(['en'])) }})</td>
                    <td>{{ $unit->dates->map->display()->join('; ') }}</td>
                    <td>{{ $unit->described('conditions-governing-access', ['en']) }}</td>
                    <td>{{ ($unit->ancestors()[0] ?? null)?->described('title', ['en']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
