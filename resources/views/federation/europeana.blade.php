{{-- OpenRiC Workspace - the Europeana EDM export (Heratio's ahg-federation Europeana page; roadmap phase 16d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Europeana'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') : '')
    <h1>{{ __('Europeana') }}</h1>
    <p><a href="{{ route('federation.admin') }}">{{ __('Federation') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('The institution\'s published records, each in the Europeana Data Model with the files the public may receive, in one bundle with a sitemap, for Europeana or an aggregator to take in. A record\'s rights are those in force on it, else the default below.') }}</p>
    <form method="post" action="{{ route('federation.europeana.save') }}">@csrf
        <label for="eu-provider">{{ __('Data provider (empty: the institution\'s name)') }}</label><input id="eu-provider" name="europeana_data_provider" value="{{ $share->europeana_data_provider }}" placeholder="{{ $institution->name }}">
        <label for="eu-country">{{ __('Country') }}</label><input id="eu-country" name="europeana_country" value="{{ $share->europeana_country }}" placeholder="South Africa">
        <label for="eu-lang">{{ __('Language (a BCP 47 tag)') }}</label><input id="eu-lang" name="europeana_language" value="{{ $share->europeana_language }}" placeholder="en">
        <label for="eu-rights">{{ __('Default rights (a RightsStatements.org or Creative Commons address)') }}</label><input id="eu-rights" name="europeana_default_rights" value="{{ $share->europeana_default_rights }}" placeholder="{{ \App\Exchange\Edm::DEFAULT_RIGHTS }}">
        <button type="submit">{{ __('Save') }}</button></form>
    <form method="post" action="{{ route('federation.europeana.run') }}">@csrf
        <label for="eu-since">{{ __('Only the records changed since (empty: every one)') }}</label><input id="eu-since" name="since" type="date">
        <button type="submit">{{ __('Export now') }}</button></form>
    <h2>{{ __('Exports') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Records') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Bundle') }}</th></tr></thead>
        <tbody>@forelse($runs as $r)<tr><td>{{ $when($r->started_at) }}@if($r->since)<br><small>{{ __('since :date', ['date' => $when($r->since)]) }}</small>@endif</td><td>{{ $r->record_count }}</td>
            <td>{{ \App\Models\Concept::find($r->status_id)?->label($lang) }}@if($r->error)<br><small>{{ \Illuminate\Support\Str::limit($r->error, 200) }}</small>@endif</td>
            <td>@if($r->storage_path)<a href="{{ route('federation.europeana.download', $r->id) }}">{{ __('Download') }}</a> · {{ \Illuminate\Support\Number::fileSize((int) $r->bundle_size, 1) }}@endif</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
