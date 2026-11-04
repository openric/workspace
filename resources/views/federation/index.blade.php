{{-- OpenRiC Workspace - federation: what the institution shares, its peers and its federated searches (Heratio's ahg-federation; roadmap phase 16a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Federation'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->timezone(config('workspace.timezone'))->format('Y-m-d H:i') : '')
    <h1>{{ __('Federation') }}</h1>
    <p><a href="#union">{{ __('Union catalogue') }}</a> · <a href="#vocabularies">{{ __('Vocabularies') }}</a> · <a href="#joins">{{ __('Requests to join') }}</a> · <a href="{{ route('federation.loans') }}">{{ __('Loans') }}</a> · <a href="{{ route('federation.europeana') }}">{{ __('Europeana') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Peers are other catalogues the institution searches at once: another institution on this installation, another OpenRiC Workspace or a Heratio. Each answers with what it has published, signed with its key, which is pinned on first use. The institution answers its own peers at :url, signed, with only what it publishes.', ['url' => route('federation.answer')]) }}</p>

    <h2>{{ __('What the institution shares') }}</h2>
    <form method="post" action="{{ route('federation.share') }}">@csrf
        <label><input type="checkbox" name="share_enabled" value="1" @checked($share->share_enabled)> {{ __('Answer federation peers with the published catalogue') }}</label>
        <fieldset><legend>{{ __('Only these levels of description (none ticked: every level)') }}</legend>
            @foreach($levels as $l)<label style="display: inline-block; margin-right: 1rem"><input type="checkbox" name="levels[]" value="{{ $l->id }}" @checked(in_array($l->id, (array) $share->levels, true))> {{ $l->label($lang) }}</label>@endforeach</fieldset>
        <fieldset><legend>{{ __('The institution\'s own vocabularies it offers its peers') }}</legend>
            @forelse($vocabularies as $v)<label style="display: inline-block; margin-right: 1rem"><input type="checkbox" name="vocabularies[]" value="{{ $v->id }}" @checked(in_array($v->id, (array) $share->shared_vocabularies, true))> {{ $v->title }}</label>@empty<p class="meta">{{ __('It has no vocabulary of its own.') }}</p>@endforelse</fieldset>
        <label><input type="checkbox" name="require_verified" value="1" @checked($share->require_verified)> {{ __('Drop what a peer answers unless it is signed by the peer\'s pinned key') }}</label>
        <button type="submit">{{ __('Save') }}</button></form>

    <h2>{{ __('Peers') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Peer') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Last searched') }}</th><th scope="col">{{ __('Trust') }}</th><th scope="col">{{ __('Protocol') }}</th></tr></thead>
        <tbody>@forelse($peers as $p)<tr><td>{{ $p->name }}@unless($p->is_active) · <strong>{{ __('off') }}</strong>@endunless<br><small>{{ $p->peerInstitution?->name ?? $p->base_url }}</small></td><td>{{ $p->type->label($lang) }}</td>
            <td>@if($p->last_search_at){{ $when($p->last_search_at) }}, {{ $p->lastStatus?->label($lang) }}@if($p->avg_response_ms) · {{ $p->avg_response_ms }} ms @endif @else{{ __('not yet') }}@endif</td>
            <td>{{ $p->trustLevel?->label($lang) }}@if($p->lastVerification) · {{ $p->lastVerification->label($lang) }}@endif @if($p->pinned_key_id)<br><small>{{ __('key :kid pinned :date', ['kid' => $p->pinned_key_id, 'date' => $when($p->key_pinned_at)]) }}</small>@endif</td>
            <td>@if($p->discoveryStatus){{ $p->discoveryStatus->label($lang) }}@if($p->protocol_version) {{ $p->protocol_version }}@endif @if($p->declared_surfaces)<br><small>{{ implode(', ', $p->declared_surfaces) }}</small>@endif @if($p->maturity_grade)<br><small>{{ __('maturity :grade', ['grade' => $p->maturity_grade]) }}</small>@endif @endif</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <form method="post" action="{{ route('federation.probe-all') }}">@csrf<button type="submit">{{ __('Probe every peer\'s protocol') }}</button></form>
    @foreach($peers as $p)
        <details><summary>{{ __('Change') }}: {{ $p->name }}</summary>
            <form method="post" action="{{ route('federation.peers.update', $p) }}">@csrf @include('federation._peer', ['p' => $p, 'x' => 'p'.$loop->index, 'trustLevels' => $trustLevels])<button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('federation.peers.test', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Test it') }}</button></form>
            @if(in_array($p->type->notation, ['workspace', 'heratio'], true))<form method="post" action="{{ route('federation.peers.probe', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Probe its protocol') }}</button></form>@endif
            @if($p->pinned_key_id)<form method="post" action="{{ route('federation.peers.clear-pin', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Clear the pinned key') }}</button></form>@endif
            @if($p->type->notation === 'oai-pmh')<form method="post" action="{{ route('federation.peers.harvest', $p) }}" style="display: inline">@csrf<button type="submit">{{ __('Harvest it now') }}</button></form>
                <form method="post" action="{{ route('federation.peers.harvest', $p) }}" style="display: inline">@csrf<input type="hidden" name="full" value="1"><button type="submit">{{ __('Harvest it in full') }}</button></form>
                @if($p->last_harvest_at)<p class="meta">{{ __('Last harvested :date, :status, :count records.', ['date' => $when($p->last_harvest_at), 'status' => $p->lastHarvestStatus?->label($lang), 'count' => $p->last_harvest_records]) }}@if($p->harvestRepository) {{ __('Its records are kept in :repository, as drafts.', ['repository' => \App\Relations\EntityKinds::label($p->harvestRepository)]) }}@endif</p>@endif
            @endif</details>
    @endforeach
    <details @if($peers->isEmpty()) open @endif><summary>{{ __('Add a peer') }}</summary><form method="post" action="{{ route('federation.peers.store') }}">@csrf @include('federation._peer', ['p' => null, 'x' => 'pnew', 'trustLevels' => $trustLevels])<button type="submit">{{ __('Add') }}</button></form></details>

    <h2 id="vocabularies">{{ __('Vocabularies synchronised with peers') }}</h2>
    <p>{{ __('A peer\'s vocabulary taken into one of the institution\'s own: each concept gets the institution\'s IRI and keeps the peer\'s as an exact match. The institution offers its own at :url.', ['url' => route('federation.vocabularies')]) }}</p>
    <table><thead><tr><th scope="col">{{ __('Peer') }}</th><th scope="col">{{ __('Its vocabulary') }}</th><th scope="col">{{ __('Into') }}</th><th scope="col">{{ __('Last synced') }}</th></tr></thead>
        <tbody>@forelse($syncs as $y)<tr><td>{{ $y->peer_name }}</td><td>{{ $y->remote_title ?? $y->remote_vocabulary }}</td><td>{{ $y->vocabulary_title }}</td>
            <td>@if($y->last_sync_at){{ $when($y->last_sync_at) }}, {{ \App\Models\Concept::find($y->last_status_id)?->label($lang) }} · {{ __(':added new, :updated updated, :conflicts the institution had', ['added' => $y->last_terms_added, 'updated' => $y->last_terms_updated, 'conflicts' => $y->last_conflicts]) }}@else{{ __('not yet') }}@endif
                <form method="post" action="{{ route('federation.syncs.run', $y->id) }}">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Sync it now') }}</button></form></td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    @if($peers->isNotEmpty() && $vocabularies->isNotEmpty())
        <details><summary>{{ __('Sync a vocabulary') }}</summary><form method="post" action="{{ route('federation.syncs.store') }}">@csrf
            <label for="vs-peer">{{ __('Peer') }}</label><select id="vs-peer" name="peer_id">@foreach($peers->filter(fn ($p) => in_array($p->type->notation, ['workspace', 'heratio'], true)) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
            <label for="vs-remote">{{ __('Its vocabulary (the identifier its /federation/vocabularies gives)') }}</label><input id="vs-remote" name="remote_vocabulary" required>
            <label for="vs-title">{{ __('Its title') }}</label><input id="vs-title" name="remote_title">
            <label for="vs-into">{{ __('Into the institution\'s vocabulary') }}</label><select id="vs-into" name="vocabulary_id">@foreach($vocabularies as $v)<option value="{{ $v->id }}">{{ $v->title }}</option>@endforeach</select>
            <label for="vs-dir">{{ __('Direction') }}</label><select id="vs-dir" name="direction">@foreach($directions as $d)<option value="{{ $d->notation }}">{{ $d->label($lang) }}</option>@endforeach</select>
            <label for="vs-rule">{{ __('Where the institution has the concept already') }}</label><select id="vs-rule" name="conflict_rule">@foreach($conflictRules as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
            <label for="vs-interval">{{ __('Sync every so many hours (empty: only when asked)') }}</label><input id="vs-interval" name="sync_interval_hours" type="number" min="1">
            <label><input type="checkbox" name="sync_enabled" value="1" checked> {{ __('Active') }}</label>
            <button type="submit">{{ __('Add') }}</button></form></details>
    @endif

    <h2 id="joins">{{ __('Requests to join') }}</h2>
    <p>{{ __('Institutions ask to join the network at :url; approving one makes it a peer, trusted at the basic level.', ['url' => route('federation.join')]) }}</p>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Institution') }}</th><th scope="col">{{ __('What it shares') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($joins as $j)@php($js = \App\Models\Concept::find($j->status_id))<tr><td>{{ $when($j->created_at) }}</td>
            <td>{{ $j->institution_name }}<br><small>{{ $j->contact_name }}, {{ $j->contact_email }} · {{ $j->base_url }}</small></td><td>{{ $j->what_they_share }}@if($j->notes)<br><small>{{ $j->notes }}</small>@endif</td>
            <td>{{ $js?->label($lang) }}@if($j->review_note)<br><small>{{ $j->review_note }}</small>@endif
                @if($js?->notation === 'pending')<form method="post" action="{{ route('federation.joins.decide', [$j->id, 'approve']) }}">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Approve') }}</button></form>
                    <form method="post" action="{{ route('federation.joins.decide', [$j->id, 'decline']) }}">@csrf<input name="review_note" aria-label="{{ __('Why') }}" placeholder="{{ __('Why') }}"><button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Decline') }}</button></form>@endif</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>

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
