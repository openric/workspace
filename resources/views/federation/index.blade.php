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
            @if($p->pinned_key_id)<form method="post" action="{{ route('federation.peers.clear-pin', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Clear the pinned key') }}</button></form>@endif
            @if($p->type->notation === 'oai-pmh')<form method="post" action="{{ route('federation.peers.harvest', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Harvest it now') }}</button></form>
                <form method="post" action="{{ route('federation.peers.harvest', $p) }}" style="display: inline">@csrf<input type="hidden" name="full" value="1"><button type="submit">{{ __('Harvest it in full') }}</button></form>
                @if($p->last_harvest_at)<p class="meta">{{ __('Last harvested :date, :status, :count records.', ['date' => $when($p->last_harvest_at), 'status' => $p->lastHarvestStatus?->label($lang), 'count' => $p->last_harvest_records]) }}@if($p->harvestRepository) {{ __('Its records are kept in :repository, as drafts.', ['repository' => \App\Relations\EntityKinds::label($p->harvestRepository)]) }}@endif</p>@endif
            @endif</details>
    @endforeach
    <details @if($peers->isEmpty()) open @endif><summary>{{ __('Add a peer') }}</summary><form method="post" action="{{ route('federation.peers.store') }}">@csrf @include('federation._peer', ['p' => null, 'x' => 'pnew'])<button type="submit">{{ __('Add') }}</button></form></details>

    <h2>{{ __('Search the peers') }}</h2>
    <form method="get" action="{{ route('federation.admin') }}" role="search"><label for="fq">{{ __('Search') }}</label><input id="fq" name="q" value="{{ $query }}"><button type="submit">{{ __('Search') }}</button></form>
    @if($found)@include('federation._results', ['found' => $found])@endif

    @if($sessions->isNotEmpty())
        <h2>{{ __('Harvests') }}</h2>
        <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Peer') }}</th><th scope="col">{{ __('Records') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>@foreach($sessions as $h)<tr><td>{{ $when($h->started_at) }}</td><td>{{ $h->peer_name }}@if($h->is_full_harvest) ({{ __('in full') }})@endif</td>
                <td>{{ __(':total: :created new, :updated updated, :deleted deleted, :errors not taken in', ['total' => $h->records_total, 'created' => $h->records_created, 'updated' => $h->records_updated, 'deleted' => $h->records_deleted, 'errors' => $h->records_errors]) }}</td>
                <td>{{ \App\Models\Concept::find($h->status_id)?->label($lang) }}@if($h->error_message)<br><small>{{ \Illuminate\Support\Str::limit($h->error_message, 200) }}</small>@endif</td></tr>@endforeach</tbody></table>
    @endif

    <h2 id="union">{{ __('Union catalogue') }}</h2>
    <p>{{ __('The union holds the records of its members, each opted in: the institution\'s own published records, of the levels it shares, and those its other members publish, fetched from their catalogues each night. It is served to them at :url and searched in the public catalogue.', ['url' => route('federation.union-records')]) }}
        {{ trans_choice(':count record in it now.|:count records in it now.', $unionCount, ['count' => $unionCount]) }}</p>
    <form method="post" action="{{ route('federation.union.publish') }}">@csrf<button type="submit">{{ __('Put our records in now') }}</button></form>
    <table><thead><tr><th scope="col">{{ __('Member') }}</th><th scope="col">{{ __('Address') }}</th><th scope="col">{{ __('In the union') }}</th><th scope="col">{{ __('Last fetched') }}</th></tr></thead>
        <tbody>@foreach($members as $m)<tr><td>{{ $m->name }}@if($m->is_self) ({{ __('this institution') }})@endif</td><td>{{ $m->base_url }}</td><td>{{ $m->is_enabled ? __('yes') : __('no') }}</td><td>{{ $when($m->last_fetched_at) }}</td></tr>@endforeach</tbody></table>
    @foreach($members as $m)
        <details><summary>{{ __('Change') }}: {{ $m->name }}</summary>
            <form method="post" action="{{ route('federation.members.update', $m->id) }}">@csrf @include('federation._member', ['m' => $m, 'x' => 'm'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
            @unless($m->is_self)<form method="post" action="{{ route('federation.members.fetch', $m->id) }}">@csrf<button type="submit">{{ __('Fetch its records now') }}</button></form>@endunless</details>
    @endforeach
    <details><summary>{{ __('Add a member') }}</summary><form method="post" action="{{ route('federation.members.store') }}">@csrf @include('federation._member', ['m' => null, 'x' => 'mnew'])<button type="submit">{{ __('Add') }}</button></form></details>
    <form method="get" action="{{ route('federation.admin') }}#union" role="search"><label for="uq">{{ __('Search the union') }}</label><input id="uq" name="u" value="{{ $unionQuery }}"><button type="submit">{{ __('Search') }}</button></form>
    @if($unionFound)@include('federation._union', ['found' => $unionFound])@endif

    <h2>{{ __('Searches') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Search') }}</th><th scope="col">{{ __('Peers') }}</th><th scope="col">{{ __('Results') }}</th></tr></thead>
        <tbody>@forelse($log as $l)<tr><td>{{ $when($l->created_at) }}</td><td>{{ $l->query_text }}</td><td>{{ __(':responded of :queried answered', ['responded' => $l->peers_responded, 'queried' => $l->peers_queried]) }}</td><td>{{ $l->total_results }} · {{ $l->total_time_ms }} ms</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
