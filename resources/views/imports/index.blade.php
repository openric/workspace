{{-- OpenRiC Workspace - imports of archival description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Imports')
@section('content')
    <p><a href="{{ route('records.index') }}">Archival descriptions</a></p>
    <h1>Imports</h1>
    <p><a href="{{ route('imports.create') }}">Import a file</a></p>
    @if($imports->isEmpty())
        <p>No imports yet.</p>
    @else
        <table>
            <thead><tr><th>When</th><th>File</th><th>Source</th><th>By</th><th>State</th><th>Units</th></tr></thead>
            <tbody>
            @foreach($imports as $import)
                @php($actions = $import->report['actions'] ?? null)
                <tr>
                    <td><a href="{{ route('imports.show', $import) }}">{{ $import->created_at->format('Y-m-d H:i') }}</a></td>
                    <td>{{ $import->file_name }}</td><td>{{ $import->source }}</td><td>{{ $import->user->name }}</td>
                    <td>{{ $import->committed_at ? 'Imported '.$import->committed_at->format('Y-m-d H:i') : 'Dry run' }}</td>
                    <td>@if($actions){{ $actions['created'] }} new, {{ $actions['updated'] }} updated, {{ $actions['unchanged'] }} unchanged, {{ $actions['rejected'] }} not imported @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
