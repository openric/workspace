{{-- OpenRiC Workspace - a repatriation claim's shared record, for the claimant's representative holding its link (roadmap phase 17d2), after Heratio's repatriation/shared (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Shared record'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Shared record') }}</h1>
    <p>{{ __('A record and dialogue shared between the holding institution and the community of origin.') }} ({{ $institution->name }})</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <dl>
        <dt>{{ __('Object') }}</dt><dd>@if($unit)<a href="{{ route('discover.record', $unit->id) }}">{{ \App\Relations\EntityKinds::label($unit, $lang) }}</a>@else{{ \App\Relations\EntityKinds::label($claim->record, $lang) }}@endif</dd>
        <dt>{{ __('Claimant community') }}</dt><dd>{{ $claim->claimant_community ?: '-' }}</dd>
        <dt>{{ __('Place of origin') }}</dt><dd>{{ $claim->origin_place ?: '-' }}</dd>
        <dt>{{ __('Current holder') }}</dt><dd>{{ $claim->current_holder ?: '-' }}</dd>
        <dt>{{ __('Stage of dialogue') }}</dt><dd>{{ $claim->status->label($lang) }}</dd>
        <dt>{{ __('Documented evidence') }}</dt><dd style="white-space: pre-line">{{ $claim->evidence_summary ?: '-' }}</dd>
    </dl>
    <h2>{{ __('Where the dialogue stands') }}</h2>
    <p class="meta">{{ __('A status describes where a dialogue stands, not a legal determination.') }}</p>
    @include('repatriation._history', ['claim' => $claim])
    <h2>{{ __('Dialogue') }}</h2>
    @forelse($messages as $m)
        <p><strong>{{ $m->author_name ?: __('Unnamed') }}</strong> · {{ $m->role->label($lang) }} · {{ $m->created_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}<br><span style="white-space: pre-line">{{ $m->body }}</span></p>
    @empty<p>{{ __('No messages have been shared yet.') }}</p>@endforelse
    @if($share->can_message)
        <form method="post" action="{{ route('repatriation.shared.message', $token) }}">@csrf
            <p><label for="sm-body">{{ __('Add to the dialogue') }}</label><br><textarea id="sm-body" name="body" required rows="4" cols="70"></textarea></p>
            <button type="submit">{{ __('Send message') }}</button>
        </form>
    @else<p>{{ __('This link is read-only. You can follow the dialogue here.') }}</p>@endif
@endsection
