{{-- OpenRiC Workspace - at-risk flags federation peers pushed, to accept or decline (roadmap phase 17d3), after Heratio's endangered/inbound (ahg-semantic-search). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Pushed at-risk flags'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Pushed at-risk flags') }}</h1>
    <p>{{ __('A flag a federation peer pushes is kept here, never in this institution\'s own register, and appears on the network board only once accepted.') }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Pending review') }}: {{ $counts['pending'] ?? 0 }} · {{ __('Accepted') }}: {{ $counts['accepted'] ?? 0 }} · {{ __('Declined') }}: {{ $counts['declined'] ?? 0 }}</p>
    @if($flags->isEmpty())<p>{{ __('No pending pushed flags. When a peer pushes an at-risk flag, it will appear here for review.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Pushed by') }}</th><th scope="col">{{ __('Risk') }}</th><th scope="col">{{ __('Urgency') }}</th><th scope="col">{{ __('Reason') }}</th><th scope="col">{{ __('Received') }}</th><th scope="col">{{ __('Decision') }}</th></tr></thead>
        <tbody>@foreach($flags as $f)<tr><td>@if($f->catalogue_url)<a href="{{ $f->catalogue_url }}" rel="noopener nofollow">{{ $f->title ?: $f->reference }}</a>@else{{ $f->title ?: $f->reference }}@endif</td>
            <td>{{ $f->source_peer_name ?: $f->source_peer_base_url }} <small>({{ $f->peer_verified ? __('verified') : __('unverified') }})</small></td>
            <td>{{ $f->risk->label($lang) }}</td><td>{{ $f->urgency->label($lang) }}</td><td>{{ \Illuminate\Support\Str::limit((string) $f->reason, 160) }}</td><td>{{ $f->received_at->format('Y-m-d') }}</td>
            <td><form method="post" action="{{ route('heritage.review', $f) }}">@csrf<button type="submit" name="decision" value="accepted">{{ __('Accept') }}</button> <button type="submit" name="decision" value="declined">{{ __('Decline') }}</button></form></td></tr>@endforeach</tbody></table>
    @endif
    <p><a href="{{ route('heritage.at-risk') }}">{{ __('Back to worklist') }}</a></p>
@endsection
