{{-- OpenRiC Workspace - share links (roadmap phase 19a), after Heratio's share link administration (ahg-share-link). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Share links'))
@section('content')
    <h1>{{ __('Share links') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('share_link')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>{{ $all ? __('Every link of the institution.') : __('The links you issued.') }} {{ __('A link is issued on a record\'s page.') }}</p>
    @if($links->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Recipient') }}</th><th scope="col">{{ __('Issued by') }}</th><th scope="col">{{ __('Until') }}</th><th scope="col">{{ __('Uses') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@foreach($links as $l)<tr><td><a href="{{ route('records.show', $l->record) }}#share-links">{{ \App\Relations\EntityKinds::label($l->record) }}</a></td><td>{{ $l->recipient_email }}</td>
            <td>{{ $l->issuedBy?->name }}</td><td>{{ $l->expires_at->format('Y-m-d') }}</td><td>{{ $l->access_count }}@if($l->max_access) / {{ $l->max_access }}@endif ({{ trans_choice(':count event|:count events', $l->events_count, ['count' => $l->events_count]) }})</td>
            <td>@if($l->revoked_at){{ __('revoked by :who on :date', ['who' => $l->revokedBy?->name ?? '-', 'date' => $l->revoked_at->format('Y-m-d')]) }}@if($l->revoke_reason): {{ $l->revoke_reason }}@endif@elseif($l->expires_at->isPast()){{ __('expired') }}@else
                <form method="post" action="{{ route('share-links.revoke', $l) }}">@csrf<input name="reason" size="16" aria-label="{{ __('Why it is revoked') }}" placeholder="{{ __('Why') }}"> <button type="submit">{{ __('Revoke') }}</button></form>@endif</td></tr>@endforeach</tbody></table>
    @endif
@endsection
