{{-- OpenRiC Workspace - the installation's Archivematica: its settings, the transfers sent and the packages it gave (Heratio's ahg-archivematica settings and jobs; roadmap phase 15d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Archivematica'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d?->timezone(config('workspace.timezone'))->format('Y-m-d H:i'))
    <h1>{{ __('Archivematica') }}</h1>
    <p><a href="{{ route('preservation.index') }}">{{ __('Preservation') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A unit of description is sent to Archivematica from its information packages page: its masters are staged in the transfer source, the transfer started and approved, and followed to its AIP. The DIPs the Storage Service holds are fetched each hour where that is switched on, and Archivematica can upload a DIP here as it does to AtoM, by SWORD at :url, signed in with an account\'s e-mail address and an API key with the write scope.', ['url' => route('sword.service-root')]) }}</p>
    @if($ping)<ul>@foreach($ping as $service => $answer)<li>{{ $service === 'dashboard' ? __('Dashboard') : __('Storage Service') }}: {{ $answer }}</li>@endforeach</ul>@endif
    @if($operator)
        <form method="post" action="{{ route('archivematica.save') }}">@csrf
            <h2>{{ __('Dashboard') }}</h2>
            <label for="am-durl">{{ __('Address') }}</label><input id="am-durl" name="dashboard_url" value="{{ $settings->dashboard_url }}" placeholder="https://archivematica.example.org">
            <label for="am-duser">{{ __('User') }}</label><input id="am-duser" name="dashboard_username" value="{{ $settings->dashboard_username }}">
            <label for="am-dkey">{{ __('API key') }}@if($settings->dashboard_api_key) ({{ __('kept; empty keeps it') }})@endif</label><input id="am-dkey" name="dashboard_api_key" type="password" autocomplete="off">
            <label for="am-pipeline">{{ __('Pipeline UUID') }}</label><input id="am-pipeline" name="pipeline_uuid" value="{{ $settings->pipeline_uuid }}">
            <label for="am-location">{{ __('Transfer source location UUID (from the Storage Service\'s Locations)') }}</label><input id="am-location" name="transfer_source_location_uuid" value="{{ $settings->transfer_source_location_uuid }}">
            <label for="am-source">{{ __('Transfer source folder, as Archivematica reads it') }}</label><input id="am-source" name="transfer_source_path" value="{{ $settings->transfer_source_path }}">
            <label for="am-staging">{{ __('The same folder, as this server writes it') }}</label><input id="am-staging" name="staging_path" value="{{ $settings->staging_path }}">
            <label for="am-type">{{ __('Transfer type') }}</label><select id="am-type" name="transfer_type">@foreach(['standard', 'zipfile', 'unzipped bag', 'zipped bag'] as $t)<option value="{{ $t }}" @selected($settings->transfer_type === $t)>{{ $t }}</option>@endforeach</select>
            <h2>{{ __('Storage Service') }}</h2>
            <label for="am-surl">{{ __('Address') }}</label><input id="am-surl" name="storage_url" value="{{ $settings->storage_url }}" placeholder="https://archivematica.example.org:8000">
            <label for="am-suser">{{ __('User') }}</label><input id="am-suser" name="storage_username" value="{{ $settings->storage_username }}">
            <label for="am-skey">{{ __('API key') }}@if($settings->storage_api_key) ({{ __('kept; empty keeps it') }})@endif</label><input id="am-skey" name="storage_api_key" type="password" autocomplete="off">
            <label><input type="checkbox" name="fetch_dips" value="1" @checked($settings->fetch_dips)> {{ __('Fetch the DIPs it holds each hour') }}</label>
            <input type="hidden" name="dip_match_strategy" value="identifier">
            <h2>{{ __('SWORD deposits') }}</h2>
            <label for="am-deposit">{{ __('Folder a deposit may name by Content-Location (where Archivematica copies a DIP)') }}</label><input id="am-deposit" name="deposit_path" value="{{ $settings->deposit_path }}">
            <label for="am-timeout">{{ __('Wait for an answer, in seconds') }}</label><input id="am-timeout" name="timeout_seconds" type="number" min="5" max="600" value="{{ $settings->timeout_seconds }}">
            <button type="submit">{{ __('Save') }}</button></form>
        <form method="post" action="{{ route('archivematica.ping') }}">@csrf<button type="submit">{{ __('Ask whether they answer') }}</button></form>
    @else
        <p class="meta">{{ __('Archivematica is set up by the installation\'s operators.') }}</p>
    @endif
    <h2>{{ __('Jobs') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Unit') }}</th><th scope="col">{{ __('Direction') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Step') }}</th></tr></thead>
        <tbody>@forelse($jobs as $j)<tr><td>{{ $when($j->created_at) }}</td><td>@if($j->record)<a href="{{ route('packages.index', $j->record) }}">{{ \App\Relations\EntityKinds::label($j->record) }}</a>@endif</td>
            <td>{{ $j->direction->label($lang) }}</td><td>{{ $j->status->label($lang) }}@if($j->error)<br><small>{{ \Illuminate\Support\Str::limit($j->error, 200) }}</small>@endif</td><td>{{ $j->microservice }}</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('Packages from Archivematica') }}</h2>
    <table><thead><tr><th scope="col">DIP</th><th scope="col">AIP</th><th scope="col">{{ __('Unit') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Files') }}</th></tr></thead>
        <tbody>@forelse($links as $l)<tr><td><small>{{ $l->dip_uuid }}</small></td><td><small>{{ $l->aip_uuid }}</small></td><td>@if($l->record){{ \App\Relations\EntityKinds::label($l->record) }}@endif</td>
            <td>{{ $l->status->label($lang) }}@if($l->note)<br><small>{{ \Illuminate\Support\Str::limit($l->note, 200) }}</small>@endif</td><td>{{ $l->files_taken }}</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
