{{-- OpenRiC Workspace - the register of repatriation claims (roadmap phase 17d2), after Heratio's repatriation/index (ahg-semantic-search), with the institution's choices. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Repatriation claims'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Repatriation claims') }}</h1>
    <p class="meta">{{ __(\App\Repatriation\Claims::DISCLAIMER) }}</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('A claim is registered from the page of the description it concerns.') }} <a href="{{ route('repatriation.knowledge') }}">{{ __('Knowledge to review') }}</a> ({{ $pending }})</p>
    <p>@if($status === null)<strong>{{ __('All') }}</strong>@else<a href="{{ route('repatriation.index') }}">{{ __('All') }}</a>@endif
        @foreach($statuses as $s) · @if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('repatriation.index', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif ({{ $counts[$s->notation] ?? 0 }})@endforeach</p>
    @if($claims->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Claimant community') }}</th><th scope="col">{{ __('Object') }}</th><th scope="col">{{ __('Place of origin') }}</th><th scope="col">{{ __('Current holder') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Updated') }}</th></tr></thead>
        <tbody>@foreach($claims as $c)<tr><td><a href="{{ route('repatriation.dialogue', $c) }}">{{ $c->claimant_community ?: __('Unnamed') }}</a>@if($c->lodged_publicly) <small>({{ __('lodged by the public') }})</small>@endif</td>
            <td><a href="{{ route('records.show', $c->record) }}">{{ \App\Relations\EntityKinds::label($c->record, $lang) }}</a></td><td>{{ $c->origin_place }}</td><td>{{ $c->current_holder }}</td>
            <td>{{ $c->status->label($lang) }}</td><td>{{ $c->updated_at->format('Y-m-d') }}</td></tr>@endforeach</tbody></table>
    @endif
    <h2>{{ __('Settings') }}</h2>
    <form method="post" action="{{ route('repatriation.settings') }}">@csrf
        <p><label><input type="checkbox" name="public_register" value="1" @checked($settings->public_register)> {{ __('Show the register of claims in the public catalogue, each claim about a published description as a virtual return where anyone may contribute knowledge') }}</label></p>
        <p><label><input type="checkbox" name="public_lodging" value="1" @checked($settings->public_lodging)> {{ __('Let anyone lodge a claim about a published description from the public catalogue') }}</label></p>
        <p><label><input type="checkbox" name="notify" value="1" @checked($settings->notify)> {{ __('Tell those who manage claims of each new claim and change of status, and the claimant at the claim\'s email') }}</label></p>
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
