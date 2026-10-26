{{-- OpenRiC Workspace - an import's report: the dry run, or what was imported and its reconciliation. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Import of '.$import->file_name)
@section('content')
    <p><a href="{{ route('imports.index') }}">{{ __('Import') }}</a> · <a href="{{ route('imports.create') }}">{{ __('Import another file') }}</a></p>
    <h1>Import of {{ $import->file_name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @php($report = $import->report ?? ['units' => [], 'issues' => [], 'counts' => [], 'actions' => []])
    @php($actions = $report['actions'] ?? ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0])
    @php($verb = $import->committed_at ? '' : 'would be ')
    <p>{{ \App\Exchange\Import\ImportService::FORMATS[$import->format] ?? $import->format }}, source "{{ $import->source }}", language {{ $import->language }},
        @if($import->repository)into {{ $import->repository->described('authorised-name', ['en']) }}@endif @if($import->parent), inside <a href="{{ route('records.show', $import->parent) }}">{{ $import->parent->described('title', ['en']) }}</a>@endif.
        Uploaded by {{ $import->user->name }} on {{ $import->created_at->format('Y-m-d H:i') }}. SHA-256 <code>{{ $import->file_sha256 }}</code>.</p>
    <p><strong>{{ $import->committed_at ? 'Imported on '.$import->committed_at->format('Y-m-d H:i').'.' : 'Dry run: nothing has been written.' }}</strong>
        {{ count($report['units'] ?? []) }} entries in the file: {{ $actions['created'] }} {{ $verb }}created, {{ $actions['updated'] }} {{ $verb }}updated,
        {{ $actions['unchanged'] }} unchanged, {{ $actions['rejected'] }} not imported.
        @if($report['counts'] ?? null){{ $report['counts']['values'] }} values; {{ $report['counts']['relations'] }} relations {{ $verb }}proposed for review;
            {{ $report['counts']['agents'] }} authority records, {{ $report['counts']['places'] }} places and {{ $report['counts']['concepts'] }} concepts {{ $verb }}created, linked from the entries;
            {{ $report['counts']['containers'] ?? 0 }} containers {{ $verb }}made; {{ $report['counts']['files'] ?? 0 }} files kept.@endif</p>
    <p><small>{{ __('Imported values are drafts until published, and the relations an import makes are proposed until someone accepts them. An empty field never removes a value already there.') }}</small></p>
    @if($mayCommit && ($report['units'] ?? []) !== [])
        <form method="post" action="{{ route('imports.commit', $import) }}">@csrf<button type="submit">{{ __('Import now') }}</button></form>
    @endif
    @if($rec = $report['reconciliation'] ?? null)
        <h2>{{ __('Reconciliation') }}</h2>
        <p>{{ $rec['holds'] ? 'Reconciled.' : 'Does not reconcile; look at the units below.' }}
            Of {{ $rec['in_file'] }} entries in the file, {{ $rec['imported_or_matched'] }} were imported or matched and {{ $rec['not_imported'] }} were not.
            {{ $rec['present_with_title'] }} are in the Workspace with an authorised name or title, and {{ $rec['keyed_to_source'] }} are keyed to the source, so importing it again updates them.</p>
    @endif

    <h2>Fields not taken as given ({{ count($report['issues'] ?? []) }})</h2>
    @if(($report['issues'] ?? []) === [])
        <p>{{ __('None: every field was read.') }}</p>
    @else
        <table>
            <thead><tr><th>{{ __('Where') }}</th><th>{{ __('Field') }}</th><th>{{ __('Value') }}</th><th>{{ __('What happened') }}</th></tr></thead>
            <tbody>
            @foreach($report['issues'] as $issue)
                <tr><td>{{ $issue['location'] }}</td><td>{{ $issue['field'] }}</td><td>{{ $issue['value'] }}</td>
                    <td><strong>{{ \App\Exchange\Import\Report::KINDS[$issue['kind']] ?? $issue['kind'] }}:</strong> {{ $issue['problem'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @php($route = ['agents' => 'agents.show', 'repositories' => 'repositories.show'][\App\Exchange\Import\ImportService::TABLES[$import->format] ?? ''] ?? 'records.show')
    <h2>{{ __('Entries') }}</h2>
    <table>
        <thead><tr><th>{{ __('Where') }}</th><th>{{ __('Title') }}</th><th>{{ __('Becomes') }}</th></tr></thead>
        <tbody>
        @foreach($report['units'] ?? [] as $unit)
            <tr><td>{{ $unit['location'] }}</td>
                <td>@if($unit['unit_id'] && $import->committed_at || $unit['unit_id'] && $unit['action'] !== 'created')<a href="{{ route($route, $unit['unit_id']) }}">{{ $unit['title'] ?? '[no title]' }}</a>@else{{ $unit['title'] ?? '[no title]' }}@endif</td>
                <td>{{ ['created' => $verb.'created', 'updated' => $verb.'updated', 'unchanged' => 'unchanged', 'rejected' => 'not imported'][$unit['action']] }}@if($unit['message']): {{ $unit['message'] }}@endif</td></tr>
        @endforeach
        </tbody>
    </table>
@endsection
