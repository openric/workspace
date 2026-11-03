{{-- OpenRiC Workspace - watched folders and what they and the scan API took in (PSIS's watch folders, Heratio's scan inbox; roadmap phase 22a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Intake'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    <h1>{{ __('Intake') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A watched folder takes in what is dropped in it, every minute: a CSV, spreadsheet, ZIP or EAD file is imported with the folder\'s settings; a BagIt bag is checked and its files taken in; any other file becomes a record, with the files of the same name and a Heratio scan sidecar beside it. An FTP or SFTP account kept to such a folder is how files arrive from outside. Scanners may also post to the scan API with a key that may write.') }}</p>
    <p class="meta">{{ $root ? __('Intake root on the server: :root', ['root' => $root]) : __('The installation has no intake root (WORKSPACE_INTAKE_ROOT), so no folder can be watched yet.') }}</p>

    <h2>{{ __('Watched folders') }}</h2>
    @forelse($folders as $f)
        <details><summary>{{ $f->label }} · <code>{{ $f->path }}</code> · @if($f->is_enabled){{ __('watched') }}@else<strong>{{ __('not watched') }}</strong>@endif
            @if($f->last_scan_at) · <span class="meta">{{ $f->last_scan_at->timezone($tz)->format('j M Y H:i') }}: {{ $f->last_status }} ({{ __(':count in all', ['count' => $f->files_taken]) }})</span>@endif</summary>
            <form method="post" action="{{ route('intake.update', $f) }}">@csrf @include('intake._folder-fields', ['f' => $f, 'x' => 'f'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('intake.run', $f) }}">@csrf<button type="submit">{{ __('Look in it now') }}</button></form></details>
    @empty<p>{{ __('None yet.') }}</p>@endforelse
    <details><summary>{{ __('Add a watched folder') }}</summary><form method="post" action="{{ route('intake.store') }}">@csrf @include('intake._folder-fields', ['f' => null, 'x' => 'fn'])<button type="submit">{{ __('Add') }}</button></form></details>

    <h2>{{ __('Taken in') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('File') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('What became of it') }}</th></tr></thead>
        <tbody>@forelse($log as $e)<tr><td>{{ $e->created_at->timezone($tz)->format('Y-m-d H:i') }}</td><td>{{ $e->file_name }}@if($e->size_bytes) <span class="meta">{{ \Illuminate\Support\Number::fileSize($e->size_bytes) }}</span>@endif</td>
            <td>{{ $e->folder?->label ?? __('scan API') }}</td>
            <td>{{ $e->outcome->label($lang) }}@if($e->record) · <a href="{{ route('records.show', $e->record) }}">{{ \App\Relations\EntityKinds::label($e->record) }}</a>@endif
                @if($e->import) · <a href="{{ route('imports.show', $e->import) }}">{{ __('its report') }}</a>@endif @if($e->message) · {{ $e->message }}@endif</td></tr>
        @empty<tr><td colspan="4">{{ __('Nothing yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
