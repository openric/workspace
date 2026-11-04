{{-- OpenRiC Workspace - federation: what the institution shares, its peers and its federated searches (Heratio's ahg-federation; roadmap phase 16a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Federation'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') : '')
    <h1>{{ __('Federation') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Peers are other catalogues the institution searches at once: another institution on this installation, another OpenRiC Workspace or a Heratio. Each answers with what it has published, signed with its key, which is pinned on first use. The institution answers its own peers at :url, signed, with only what it publishes.', ['url' => route('federation.answer')]) }}</p>

    <h2>{{ __('What the institution shares') }}</h2>
    <form method="post" action="{{ route('federation.share') }}">@csrf
        <label><input type="checkbox" name="share_enabled" value="1" @checked($share->share_enabled)> {{ __('Answer federation peers with the published catalogue') }}</label>
        <fieldset><legend>{{ __('Only these levels of description (none ticked: every level)') }}</legend>
            @foreach($levels as $l)<label style="display: inline-block; margin-right: 1rem"><input type="checkbox" name="levels[]" value="{{ $l->id }}" @checked(in_array($l->id, (array) $share->levels, true))> {{ $l->label($lang) }}</label>@endforeach</fieldset>
        <button type="submit">{{ __('Save') }}</button></form>

    <h2>{{ __('Peers') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Peer') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Last searched') }}</th><th scope="col">{{ __('Trust') }}</th></tr></thead>
        <tbody>@forelse($peers as $p)<tr><td>{{ $p->name }}@unless($p->is_active) · <strong>{{ __('off') }}</strong>@endunless<br><small>{{ $p->peerInstitution?->name ?? $p->base_url }}</small></td><td>{{ $p->type->label($lang) }}</td>
            <td>@if($p->last_search_at){{ $when($p->last_search_at) }}, {{ $p->lastStatus?->label($lang) }}@if($p->avg_response_ms) · {{ $p->avg_response_ms }} ms @endif @else{{ __('not yet') }}@endif</td>
            <td>{{ $p->lastVerification?->label($lang) }}@if($p->pinned_key_id)<br><small>{{ __('key :kid pinned :date', ['kid' => $p->pinned_key_id, 'date' => $when($p->key_pinned_at)]) }}</small>@endif</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    @foreach($peers as $p)
        <details><summary>{{ __('Change') }}: {{ $p->name }}</summary>
            <form method="post" action="{{ route('federation.peers.update', $p) }}">@csrf @include('federation._peer', ['p' => $p, 'x' => 'p'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('federation.peers.test', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Test it') }}</button></form>
            @if($p->pinned_key_id)<form method="post" action="{{ route('federation.peers.clear-pin', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Clear the pinned key') }}</button></form>@endif</details>
    @endforeach
    <details @if($peers->isEmpty()) open @endif><summary>{{ __('Add a peer') }}</summary><form method="post" action="{{ route('federation.peers.store') }}">@csrf @include('federation._peer', ['p' => null, 'x' => 'pnew'])<button type="submit">{{ __('Add') }}</button></form></details>

    <h2>{{ __('Search the peers') }}</h2>
    <form method="get" action="{{ route('federation.admin') }}" role="search"><label for="fq">{{ __('Search') }}</label><input id="fq" name="q" value="{{ $query }}"><button type="submit">{{ __('Search') }}</button></form>
    @if($found)@include('federation._results', ['found' => $found])@endif

    <h2>{{ __('Searches') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Search') }}</th><th scope="col">{{ __('Peers') }}</th><th scope="col">{{ __('Results') }}</th></tr></thead>
        <tbody>@forelse($log as $l)<tr><td>{{ $when($l->created_at) }}</td><td>{{ $l->query_text }}</td><td>{{ __(':responded of :queried answered', ['responded' => $l->peers_responded, 'queried' => $l->peers_queried]) }}</td><td>{{ $l->total_results }} · {{ $l->total_time_ms }} ms</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
