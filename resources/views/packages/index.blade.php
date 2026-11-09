{{-- OpenRiC Workspace - a unit's OAIS information packages and its transfers to Archivematica (Heratio's ahg-preservation packages and ahg-archivematica transfer panel; roadmap phase 15d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Information packages'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d?->timezone(config('workspace.timezone'))->format('Y-m-d H:i'))
    <h1>{{ __('Information packages') }}</h1>
    <p><a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record) }}</a>@module('archivematica') · <a href="{{ route('archivematica.index') }}">{{ __('Archivematica') }}</a>@endmodule</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('An OAIS information package is the unit and its files put together as a BagIt bag, each file with its checksum: a SIP holds the masters as they came in, an AIP the masters and preservation masters with the unit\'s METS, PREMIS and RiC-O, and a DIP the copies a reader may use. A SIP is carried on into an AIP, and an AIP into a DIP. Each package is kept in an OCFL object of its own.') }}</p>
    <table><thead><tr><th scope="col">{{ __('Package') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Files') }}</th><th scope="col">{{ __('Built') }}</th></tr></thead>
        <tbody>@forelse($packages as $p)<tr><td><a href="{{ route('packages.show', $p) }}">{{ $p->name }}</a>@if($p->parent)<br><small>{{ __('from :name', ['name' => $p->parent->name]) }}</small>@endif</td>
            <td>{{ strtoupper($p->type->notation) }}</td><td>{{ $p->status->label($lang) }}</td>
            <td>{{ $p->object_count }} · {{ \Illuminate\Support\Number::fileSize((int) $p->package_size, 1) }}</td><td>{{ $when($p->built_at) }}</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('Build a package') }}</h2>
    <form method="post" action="{{ route('packages.store', $record) }}">@csrf
        <label for="pk-type">{{ __('Kind') }}</label><select id="pk-type" name="type"><option value="sip">{{ __('Submission Information Package') }} (SIP)</option><option value="aip" selected>{{ __('Archival Information Package') }} (AIP)</option><option value="dip">{{ __('Dissemination Information Package') }} (DIP)</option></select>
        <label for="pk-format">{{ __('Serialised as') }}</label><select id="pk-format" name="format"><option value="zip">ZIP</option><option value="tar">TAR</option></select>
        <label for="pk-algorithm">{{ __('Manifest checksums') }}</label><select id="pk-algorithm" name="algorithm"><option value="sha256">SHA-256</option><option value="sha512">SHA-512</option></select>
        <label><input type="checkbox" name="include_descendants" value="1" checked> {{ __('With what the unit includes') }}</label>
        <label for="pk-name">{{ __('Name (empty: made from the kind, reference code and time)') }}</label><input id="pk-name" name="name" value="{{ old('name') }}">
        <label for="pk-description">{{ __('Description') }}</label><input id="pk-description" name="description" value="{{ old('description') }}">
        <label for="pk-originator">{{ __('Producer') }}</label><input id="pk-originator" name="originator" value="{{ old('originator') }}">
        <label for="pk-agreement">{{ __('Submission agreement') }}</label><input id="pk-agreement" name="submission_agreement" value="{{ old('submission_agreement') }}">
        <label for="pk-retention">{{ __('Retention period') }}</label><input id="pk-retention" name="retention_period" value="{{ old('retention_period') }}">
        <button type="submit">{{ __('Build it') }}</button></form>
    <h2>{{ __('Archivematica') }}</h2>
    @if($archivematica->dashboardConfigured())
        <form method="post" action="{{ route('packages.transfer', $record) }}">@csrf<button type="submit">{{ __('Send the unit to Archivematica') }}</button></form>
    @else
        <p class="meta">{{ __('Archivematica is not set up on this installation.') }}</p>
    @endif
    @if($jobs->isNotEmpty())
        <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Direction') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Step') }}</th><th scope="col">AIP</th></tr></thead>
            <tbody>@foreach($jobs as $j)<tr><td>{{ $when($j->created_at) }}</td><td>{{ $j->direction->label($lang) }}</td><td>{{ $j->status->label($lang) }}@if($j->error)<br><small>{{ \Illuminate\Support\Str::limit($j->error, 200) }}</small>@endif</td>
                <td>{{ $j->microservice }}</td><td>{{ $j->aip_uuid }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
