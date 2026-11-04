{{-- OpenRiC Workspace - how the institution mints DOIs (Heratio's DOI config; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('DOI settings'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('DOI settings') }}</h1>
    @include('dois._nav')
    <p>{{ __('The DataCite account, its repository ID, password and prefix, and whether it is DataCite\'s test service, is the one the research services keep.') }} <a href="{{ route('research.services.settings') }}">{{ __('ORCID and DataCite') }}</a>
        @if($account) {{ $account->datacite_test ? __('It is set up, for the test service.') : __('It is set up, for production.') }} @else <strong>{{ __('It is not set up yet.') }}</strong> @endif</p>
    <form method="post" action="{{ route('dois.settings.save') }}">@csrf
        <label><input type="checkbox" name="auto_mint" value="1" @checked($settings->auto_mint)> {{ __('Give a unit a DOI when it is published') }}</label>
        <label for="dl">{{ __('Only units of these levels (none chosen: every level)') }}</label>
        <select id="dl" name="auto_mint_level_ids[]" multiple size="6">@foreach($levels as $l)<option value="{{ $l->id }}" @selected(in_array($l->id, (array) $settings->auto_mint_level_ids, true))>{{ $l->label($lang) }}</option>@endforeach</select>
        <label><input type="checkbox" name="require_digital_object" value="1" @checked($settings->require_digital_object)> {{ __('Only units with a file') }}</label>
        <label for="dp">{{ __('Publisher') }}</label><input id="dp" name="default_publisher" value="{{ $settings->default_publisher }}" placeholder="{{ auth()->user()->institution->name }}">
        <label for="dt">{{ __('Resource type') }}</label><select id="dt" name="default_resource_type_id"><option value="">{{ __('Collection for a unit with units inside it, else Text') }}</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected($settings->default_resource_type_id === $t->id)>{{ $t->label($lang) }}</option>@endforeach</select>
        <label for="dsp">{{ __('Suffix pattern') }}</label><input id="dsp" name="suffix_pattern" required value="{{ $settings->suffix_pattern }}" pattern="[A-Za-z0-9._/{}-]+">
        <p class="meta">{{ __('Made from {repository_code}, {reference_code}, {year} and {id}, such as {repository_code}/{year}/{id}.') }}</p>
        <h2>{{ __('Usage events') }}</h2>
        <label><input type="checkbox" name="events_enabled" value="1" @checked($settings->events_enabled)> {{ __('Report views and downloads of units with a findable DOI to DataCite') }}</label>
        <label for="det">{{ __('Token from DataCite Fabrica') }}</label><input id="det" name="events_token" type="password" autocomplete="off" placeholder="{{ $settings->events_token ? __('Kept; leave empty to keep it') : '' }}">
        <p class="meta">{{ __('Kept encrypted and never shown.') }}</p>
        <button type="submit">{{ __('Save') }}</button></form>
@endsection
