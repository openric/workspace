{{-- OpenRiC Workspace - privacy in the catalogue (roadmap phase 18b, ADR 0016), after Heratio's PII scan dashboard and its screening settings (ahg-privacy): where a record's files must be screened before the public receives them, the scans that found personal information and wait for a decision, and the files withheld. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Personal information in the catalogue'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('privacy.index') }}">{{ __('Privacy') }}</a></p>
    <h1>{{ __('Personal information in the catalogue') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('screening')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>{{ __('A record is scanned for personal information on its page. Its fields can be redacted for the public there, and its files released or withheld.') }}</p>
    <p>@foreach(\App\Models\Concept::choices(\App\Privacy\Screening::STATUSES)->get() as $s){{ $s->label($lang) }}: {{ $counts[$s->notation] ?? 0 }}@if(! $loop->last) · @endif @endforeach</p>

    <h2>{{ __('Where files must be screened') }}</h2>
    <p class="meta">{{ __('There, a record\'s files reach the public only once a scan that read every file found nothing, or a person released them.') }}</p>
    @forelse($scopes as $scope)
        <p>@if($scope->repository){{ $scope->repository->described('authorised-name', $lang) }} ({{ __('every record of the repository') }})@else<a href="{{ route('records.show', $scope->record) }}">{{ \App\Relations\EntityKinds::label($scope->record) }}</a> ({{ __('and everything inside it') }})@endif
            @if($scope->note) · {{ $scope->note }}@endif
            <form method="post" action="{{ route('privacy.screening.remove', $scope) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></p>
    @empty<p>{{ __('Nowhere: every record\'s files follow their own access rules.') }}</p>@endforelse
    <details><summary>{{ __('Require screening') }}</summary>
        <form method="post" action="{{ route('privacy.screening.require') }}">@csrf
            <p><label for="sc-repository">{{ __('Every record of the repository') }}</label> @include('description._ric', ['term' => null])
                <select id="sc-repository" name="repository_id"><option value="">-</option>@foreach($repositories as $r)<option value="{{ $r->id }}">{{ $r->described('authorised-name', $lang) }}</option>@endforeach</select></p>
            <p><label for="sc-record">{{ __('Or a record and everything inside it (its persistent IRI)') }}</label> @include('description._ric', ['term' => null]) <input id="sc-record" name="record" size="60" value="{{ old('record') }}"></p>
            <p><label for="sc-note">{{ __('Note') }}</label> @include('description._ric', ['term' => null]) <input id="sc-note" name="note" size="60"></p>
            <button type="submit">{{ __('Require screening') }}</button></form>
    </details>

    <h2>{{ __('Found personal information, no decision yet') }}</h2>
    @include('privacy._scan-list', ['reports' => $waiting])
    <h2>{{ __('Files withheld') }}</h2>
    @include('privacy._scan-list', ['reports' => $withheld])
@endsection
