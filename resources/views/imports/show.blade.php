{{-- OpenRiC Workspace - an import's report: the dry run, or what was imported and its reconciliation. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Import of :name', ['name' => $import->file_name]))
@section('content')
    <p><a href="{{ route('imports.index') }}">{{ __('Import') }}</a> · <a href="{{ route('imports.create') }}">{{ __('Import another file') }}</a></p>
    <h1>{{ __('Import of :name', ['name' => $import->file_name]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @php($report = $import->report ?? ['units' => [], 'issues' => [], 'counts' => [], 'actions' => []])
    @php($actions = $report['actions'] ?? ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0])
    @php($verb = $import->committed_at ? '' : 'would be ')
    <p>{{ \App\Exchange\Import\ImportService::FORMATS[$import->format] ?? $import->format }}, {{ __('source ":source", language :language', ['source' => $import->source, 'language' => $import->language]) }},
        @if($import->repository){{ __('into :repository', ['repository' => $import->repository->described('authorised-name', ['en'])]) }}@endif @if($import->parent), {{ __('inside') }} <a href="{{ route('records.show', $import->parent) }}">{{ $import->parent->described('title', ['en']) }}</a>@endif.
        {{ __('Uploaded by :name on :date.', ['name' => $import->user->name, 'date' => $import->created_at->format('Y-m-d H:i')]) }} SHA-256 <code>{{ $import->file_sha256 }}</code>.</p>
    @php($done = (bool) $import->committed_at)
    <p><strong>{{ $done ? __('Imported on :date.', ['date' => $import->committed_at->format('Y-m-d H:i')]) : __('Dry run: nothing has been written.') }}</strong>
        {{ __($done ? ':entries entries in the file: :created created, :updated updated, :unchanged unchanged, :rejected not imported.' : ':entries entries in the file: :created would be created, :updated would be updated, :unchanged unchanged, :rejected not imported.', ['entries' => count($report['units'] ?? [])] + $actions) }}
        @if($report['counts'] ?? null){{ __($done ? ':values values; :relations relations proposed for review; :agents authority records, :places places and :concepts concepts created, linked from the entries; :containers containers made; :files files kept.' : ':values values; :relations relations would be proposed for review; :agents authority records, :places places and :concepts concepts would be created, linked from the entries; :containers containers would be made; :files files kept.', ['containers' => 0, 'files' => 0] + $report['counts']) }}@endif</p>
    <p><small>{{ __('Imported values are drafts until published, and the relations an import makes are proposed until someone accepts them. An empty field never removes a value already there.') }}</small></p>
    @if($mayCommit && ($report['units'] ?? []) !== [])
        <form method="post" action="{{ route('imports.commit', $import) }}">@csrf<button type="submit">{{ __('Import now') }}</button></form>
    @endif
    @if($rec = $report['reconciliation'] ?? null)
        <h2>{{ __('Reconciliation') }}</h2>
        <p>{{ $rec['holds'] ? __('Reconciled.') : __('Does not reconcile; look at the units below.') }}
            {{ __('Of :in_file entries in the file, :imported_or_matched were imported or matched and :not_imported were not.', $rec) }}
            {{ __(':present_with_title are in the Workspace with an authorised name or title, and :keyed_to_source are keyed to the source, so importing it again updates them.', $rec) }}</p>
    @endif

    <h2>{{ __('Fields not taken as given (:count)', ['count' => count($report['issues'] ?? [])]) }}</h2>
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
                <td>{{ __(['created' => $import->committed_at ? 'created' : 'would be created', 'updated' => $import->committed_at ? 'updated' : 'would be updated', 'unchanged' => 'unchanged', 'rejected' => 'not imported'][$unit['action']]) }}@if($unit['message']): {{ $unit['message'] }}@endif</td></tr>
        @endforeach
        </tbody>
    </table>
@endsection
