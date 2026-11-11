{{-- OpenRiC Workspace - one DOI, what was registered and what was asked of DataCite (Heratio's DOI view; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $doi->doi)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $doi->doi }}</h1>
    @include('dois._nav')
    <dl>
        <dt>{{ __('Unit of description') }}</dt><dd>@if($doi->record)<a href="{{ route('records.show', $doi->record) }}">{{ \App\Relations\EntityKinds::label($doi->record) }}</a>@endif</dd>
        <dt>{{ __('State') }}</dt><dd>{{ $doi->state->label($lang) }}@if($doi->test) ({{ __('DataCite\'s test service') }})@endif</dd>
        <dt>{{ __('Resolves to') }}</dt><dd>{{ $doi->metadata['url'] ?? $doi->record?->iri }}</dd>
        <dt>{{ __('Minted') }}</dt><dd>{{ $doi->minted_at?->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</dd>
        <dt>{{ __('Last sent to DataCite') }}</dt><dd>{{ $doi->last_sync_at?->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</dd>
        <dt>{{ __('DOI') }}</dt><dd><a href="{{ $doi->url() }}">{{ $doi->url() }}</a></dd>
    </dl>
    <details><summary>{{ __('The metadata registered') }}</summary><pre style="white-space: pre-wrap">{{ json_encode($doi->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
    <h2>{{ __('What was asked of DataCite') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Outcome') }}</th><th scope="col">{{ __('State') }}</th><th scope="col">{{ __('By') }}</th></tr></thead>
        <tbody>@forelse($log as $l)<tr><td>{{ \Illuminate\Support\Carbon::parse($l->performed_at)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</td><td>{{ $l->action }}</td><td>{{ $l->succeeded ? __('Done') : (json_decode($l->details, true)['error'] ?? __('Failed')) }}</td>
            <td>{{ $l->state_before }}@if($l->state_after) &rarr; {{ $l->state_after }}@endif</td><td>{{ $l->performed_by ?? __('the system') }}</td></tr>
        @empty<tr><td colspan="5">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
@endsection
