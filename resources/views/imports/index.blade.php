{{-- OpenRiC Workspace - imports of archival description, authority records and repositories. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Import'))
@section('content')
    <p><a href="{{ route('records.index') }}">{{ __('Archival descriptions') }}</a></p>
    <h1>{{ __('Import') }}</h1>
    <p><a href="{{ route('imports.create') }}">{{ __('Import a file') }}</a></p>
    @if($imports->isEmpty())
        <p>{{ __('No imports yet.') }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('When') }}</th><th>{{ __('File') }}</th><th>{{ __('Source') }}</th><th>{{ __('By') }}</th><th>{{ __('What') }}</th><th>{{ __('State') }}</th><th>{{ __('Entries') }}</th></tr></thead>
            <tbody>
            @foreach($imports as $import)
                @php($actions = $import->report['actions'] ?? null)
                <tr>
                    <td><a href="{{ route('imports.show', $import) }}">{{ $import->created_at->format('Y-m-d H:i') }}</a></td>
                    <td>{{ $import->file_name }}</td><td>{{ $import->source }}</td><td>{{ $import->user->name }}</td>
                    <td>{{ \App\Exchange\Import\ImportService::FORMATS[$import->format] ?? $import->format }}</td>
                    <td>{{ $import->committed_at ? 'Imported '.$import->committed_at->format('Y-m-d H:i') : 'Dry run' }}</td>
                    <td>@if($actions){{ __(':created new, :updated updated, :unchanged unchanged, :rejected not imported', $actions) }} @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
