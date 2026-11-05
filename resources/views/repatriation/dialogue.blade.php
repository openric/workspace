{{-- OpenRiC Workspace - the dialogue workspace for one repatriation claim (roadmap phase 17d2), after Heratio's repatriation/dialogue (ahg-semantic-search): what is on record, the dialogue, the status and its history, the links to the shared record, and the knowledge shown with it. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Repatriation claim'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Repatriation claim') }}: {{ $claim->claimant_community ?: __('Unnamed') }}</h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if(session('new_share_url'))<p role="status"><code>{{ session('new_share_url') }}</code></p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <h2>{{ __('What is on record') }}</h2>
    <dl>
        <dt>{{ __('Object') }}</dt><dd><a href="{{ route('records.show', $claim->record) }}">{{ \App\Relations\EntityKinds::label($claim->record, $lang) }}</a></dd>
        <dt>{{ __('Claimant community') }}</dt><dd>{{ $claim->claimant_community ?: '-' }}@if($claim->claimantAgent) (<a href="{{ route('agents.show', $claim->claimantAgent) }}">{{ \App\Relations\EntityKinds::label($claim->claimantAgent, $lang) }}</a>)@endif</dd>
        <dt>{{ __('Place of origin') }}</dt><dd>{{ $claim->origin_place ?: '-' }}@if($claim->originPlace) (<a href="{{ route('places.show', $claim->originPlace) }}">{{ \App\Relations\EntityKinds::label($claim->originPlace, $lang) }}</a>)@endif</dd>
        <dt>{{ __('Current holder') }}</dt><dd>{{ $claim->current_holder ?: '-' }}</dd>
        <dt>{{ __('Stage of dialogue') }}</dt><dd>{{ $claim->status->label($lang) }}</dd>
        <dt>{{ __('Documented evidence') }}</dt><dd style="white-space: pre-line">{{ $claim->evidence_summary ?: '-' }}</dd>
        <dt>{{ __('Contact') }}</dt><dd>{{ $claim->contact ?: '-' }}@if($claim->contact_email) · {{ $claim->contact_email }}@endif</dd>
        @if($claim->notes)<dt>{{ __('Notes') }}</dt><dd style="white-space: pre-line">{{ $claim->notes }}</dd>@endif
    </dl>
    <p><a href="{{ route('repatriation.edit', $claim) }}">{{ __('Edit the claim') }}</a> · <a href="{{ route('repatriation.index') }}">{{ __('All claims') }}</a></p>

    <h2>{{ __('Dialogue') }}</h2>
    @forelse($messages as $m)
        <p><strong>{{ $m->author_name ?: __('Unnamed') }}</strong> · {{ $m->role->label($lang) }} · {{ $m->visibility->label($lang) }} · {{ $m->created_at->format('Y-m-d H:i') }}<br><span style="white-space: pre-line">{{ $m->body }}</span></p>
    @empty<p>{{ __('No messages yet.') }}</p>@endforelse
    <form method="post" action="{{ route('repatriation.message', $claim) }}">@csrf
        <p><label for="rm-body">{{ __('Add to the dialogue') }}</label><br><textarea id="rm-body" name="body" required rows="4" cols="70"></textarea></p>
        <p><label for="rm-role">{{ __('Speaking as') }}</label> <select id="rm-role" name="role">@foreach($roles->whereIn('notation', \App\Repatriation\Dialogue::STAFF_ROLES) as $r)<option value="{{ $r->notation }}">{{ $r->label($lang) }}</option>@endforeach</select>
            <label for="rm-vis">{{ __('Visibility') }}</label> <select id="rm-vis" name="visibility">@foreach($visibilities as $v)<option value="{{ $v->notation }}">{{ $v->label($lang) }}</option>@endforeach</select>
            <button type="submit">{{ __('Send message') }}</button></p>
    </form>

    <h2>{{ __('Where the dialogue stands') }}</h2>
    <form method="post" action="{{ route('repatriation.status', $claim) }}">@csrf
        <p><label for="rs-status">{{ __('Stage of dialogue') }}</label> <select id="rs-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($claim->status_id === $s->id)>{{ $s->label($lang) }}</option>@endforeach</select>
            <label for="rs-note">{{ __('Note') }}</label> <input id="rs-note" name="note" maxlength="1024" size="40"> <button type="submit">{{ __('Save') }}</button></p>
    </form>
    @include('repatriation._history', ['claim' => $claim])

    <h2>{{ __('Shared record') }}</h2>
    <p class="meta">{{ __('A link lets a claimant\'s representative read the claim, its status and the shared messages, and write in the dialogue, without an account. It is shown once, when it is made.') }}</p>
    @forelse($claim->shares as $s)
        <p>{{ $s->grantee_name ?: __('Unnamed') }} · {{ $s->role->label($lang) }} · {{ $s->can_message ? __('may write') : __('read only') }}
            @if($s->expires_at) · {{ __('until :date', ['date' => $s->expires_at->format('Y-m-d')]) }}@endif
            @if($s->last_seen_at) · {{ __('last opened :date', ['date' => $s->last_seen_at->format('Y-m-d')]) }}@endif
            @if($s->revoked_at) · <strong>{{ __('revoked') }}</strong>@elseif(! $s->isActive()) · <strong>{{ __('expired') }}</strong>
            @else <form method="post" action="{{ route('repatriation.revoke', [$claim, $s]) }}" style="display: inline">@csrf<button type="submit">{{ __('Revoke') }}</button></form>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('Make a link to the shared record') }}</summary>
        <form method="post" action="{{ route('repatriation.grant', $claim) }}">@csrf
            <p><label for="rg-name">{{ __('For') }}</label> <input id="rg-name" name="grantee_name" maxlength="255" size="30">
                <label for="rg-role">{{ __('As') }}</label> <select id="rg-role" name="grantee_role">@foreach($roles->where('notation', '!=', 'institution') as $r)<option value="{{ $r->notation }}">{{ $r->label($lang) }}</option>@endforeach</select></p>
            <p><label><input type="checkbox" name="can_message" value="1" checked> {{ __('May write in the dialogue') }}</label>
                <label for="rg-expires">{{ __('Until') }}</label> <input id="rg-expires" name="expires_at" type="date"></p>
            <button type="submit">{{ __('Make the link') }}</button>
        </form>
    </details>

    <h2>{{ __('Community knowledge') }}</h2>
    @forelse($knowledge as $k)<p><strong>{{ $k->type->label($lang) }}</strong> · {{ $k->contributor_name ?: __('Anonymous contributor') }}<br><span style="white-space: pre-line">{{ $k->body }}</span>@if($k->source)<br><small>{{ $k->source }}</small>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
@endsection
