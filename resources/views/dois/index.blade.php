{{-- OpenRiC Workspace - the DOIs of the institution's units of description (Heratio's DOI dashboard; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('DOIs'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('DOIs') }}</h1>
    @include('dois._nav')
    @if(! $account)
        <p class="error">{{ __('DataCite is not set up yet: give the institution\'s repository ID, password and prefix under the research services settings.') }} <a href="{{ route('research.services.settings') }}">{{ __('ORCID and DataCite') }}</a></p>
    @else
        <p>{{ $account->datacite_test ? __('DOIs are registered with DataCite\'s test service, under the prefix :prefix. They do not resolve at doi.org.', ['prefix' => $account->datacite_prefix]) : __('DOIs are registered with DataCite, under the prefix :prefix.', ['prefix' => $account->datacite_prefix]) }}
            {{ $settings->auto_mint ? __('A unit is given a DOI when it is published.') : __('DOIs are minted by hand.') }}</p>
    @endif
    <dl>
        @foreach($states as $s)<dt>{{ $s->label($lang) }}</dt><dd>{{ $counts[$s->id] ?? 0 }}</dd>@endforeach
        @foreach($queueStatuses as $s)@if(($queue[$s->id] ?? 0) > 0)<dt>{{ __('In the queue') }}: {{ $s->label($lang) }}</dt><dd>{{ $queue[$s->id] }}</dd>@endif @endforeach
    </dl>
    <h2>{{ __('Minted most recently') }}</h2>
    @include('dois._list', ['dois' => $recent])
@endsection
