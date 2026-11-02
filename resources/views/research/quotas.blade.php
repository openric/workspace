{{-- OpenRiC Workspace - research quotas and what each researcher has used (Heratio's quota pages; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research quotas'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($whom = fn ($p) => match ($p->scope->notation) { 'user' => $accounts->firstWhere('id', $p->scope_key)?->name, 'project' => $projects->firstWhere('id', $p->scope_key)?->title, 'role' => $kinds->firstWhere('id', $p->scope_key)?->name, default => __('Everyone') })
    <h1>{{ __('Research quotas') }}</h1>
    @include('research._head')
    <p>{{ __('For each limit the narrowest policy that sets it holds: the researcher\'s, then the project\'s, then their kind\'s, then everyone\'s. Downloads count project exports, replication packs and research workspace files; storage counts the files a researcher keeps in research workspaces.') }}</p>
    <table><thead><tr><th scope="col">{{ __('For') }}</th><th scope="col">{{ __('Period') }}</th><th scope="col">{{ __('Downloads') }}</th><th scope="col">{{ __('Storage') }}</th><th scope="col">{{ __('Warn at') }}</th><th scope="col"></th></tr></thead>
        <tbody>@forelse($policies as $p)<tr><td>{{ $p->scope->label($lang) }}: {{ $whom($p) }}@unless($p->is_active) ({{ __('not in use') }})@endunless</td><td>{{ $p->period->label($lang) }}</td>
            <td>{{ $p->max_downloads ?? '-' }}</td><td>{{ $p->max_storage_bytes !== null ? \App\Research\Quotas::bytes($p->max_storage_bytes) : '-' }}</td><td>{{ $p->soft_warn_pct }}%</td>
            <td><form method="post" action="{{ route('research.quotas.save', $p) }}">@csrf<input type="hidden" name="remove" value="1"><button type="submit">{{ __('Remove') }}</button></form></td></tr>
        @empty<tr><td colspan="6">{{ __('No limits: researchers may download and keep as much as they like.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('Add a limit') }}</h2>
    <form method="post" action="{{ route('research.quotas.save') }}">@csrf
        <label for="q-scope">{{ __('For') }}</label> @include('projects._select', ['id' => 'q-scope', 'name' => 'scope', 'choices' => $scopes, 'current' => null, 'default' => 'global'])
        <label for="q-key">{{ __('Whom') }}</label> <select id="q-key" name="scope_key"><option value="">{{ __('Everyone') }}</option>
            <optgroup label="{{ __('Kinds of researcher') }}">@foreach($kinds as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach</optgroup>
            <optgroup label="{{ __('Researchers') }}">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</optgroup>
            <optgroup label="{{ __('Projects') }}">@foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach</optgroup></select><br>
        <label for="q-period">{{ __('Period') }}</label> @include('projects._select', ['id' => 'q-period', 'name' => 'period', 'choices' => $periods, 'current' => null, 'default' => 'monthly'])
        <label for="q-dl">{{ __('Most downloads') }}</label> <input id="q-dl" name="max_downloads" type="number" min="0" style="width: 6rem">
        <label for="q-mb">{{ __('Most storage (MB)') }}</label> <input id="q-mb" name="max_storage_mb" type="number" min="0" style="width: 7rem">
        <label for="q-warn">{{ __('Warn at (%)') }}</label> <input id="q-warn" name="soft_warn_pct" type="number" min="1" max="100" value="80" style="width: 5rem">
        <input type="hidden" name="is_active" value="0"><label><input type="checkbox" name="is_active" value="1" checked> {{ __('In use') }}</label><br>
        <label for="q-notes">{{ __('Notes') }}</label> <input id="q-notes" name="notes" size="60"> <button type="submit">{{ __('Add') }}</button></form>
    <h2>{{ __('What researchers have used') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Researcher') }}</th><th scope="col">{{ __('Downloads') }}</th><th scope="col">{{ __('Storage') }}</th></tr></thead>
        <tbody>@forelse($usage as $u)<tr><td>{{ $u['user']->name }}</td><td>{{ $u['downloads'] }}@if($u['limits']['max_downloads'] !== null) / {{ $u['limits']['max_downloads'] }}@endif</td>
            <td>{{ \App\Research\Quotas::bytes($u['storage']) }}@if($u['limits']['max_storage_bytes'] !== null) / {{ \App\Research\Quotas::bytes($u['limits']['max_storage_bytes']) }}@endif</td></tr>
        @empty<tr><td colspan="3">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
@endsection
