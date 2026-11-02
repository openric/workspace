{{-- OpenRiC Workspace - the institution's ORCID client and DataCite repository account (roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('ORCID and DataCite'))
@section('content')
    <h1>{{ __('ORCID and DataCite') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('research.services.settings.update') }}">@csrf
        <h2>{{ __('ORCID') }}</h2>
        <p>{{ __('Register the Workspace as a member API client at ORCID, with this address to return to:') }} <code>{{ route('research.orcid.callback') }}</code></p>
        <label for="s-orcid-id">{{ __('Client ID') }}</label> <input id="s-orcid-id" name="orcid_client_id" size="25" placeholder="APP-XXXXXXXXXXXXXXXX" value="{{ old('orcid_client_id', $settings->orcid_client_id) }}">
        <label for="s-orcid-secret">{{ __('Client secret') }}</label> <input id="s-orcid-secret" name="orcid_client_secret" type="password" autocomplete="new-password" size="30" placeholder="{{ $settings->orcid_client_secret ? __('Kept; type to replace') : '' }}">
        <input type="hidden" name="orcid_sandbox" value="0"><label><input type="checkbox" name="orcid_sandbox" value="1" @checked($settings->orcid_sandbox)> {{ __('ORCID sandbox') }}</label>
        <h2>{{ __('DataCite') }}</h2>
        <p>{{ __('The institution\'s own DataCite repository account. DOIs are registered under its prefix.') }}</p>
        <label for="s-dc-id">{{ __('Repository ID') }}</label> <input id="s-dc-id" name="datacite_repository_id" size="20" value="{{ old('datacite_repository_id', $settings->datacite_repository_id) }}">
        <label for="s-dc-pw">{{ __('Password') }}</label> <input id="s-dc-pw" name="datacite_password" type="password" autocomplete="new-password" size="20" placeholder="{{ $settings->datacite_password ? __('Kept; type to replace') : '' }}">
        <label for="s-dc-prefix">{{ __('Prefix') }}</label> <input id="s-dc-prefix" name="datacite_prefix" size="12" placeholder="10.12345" value="{{ old('datacite_prefix', $settings->datacite_prefix) }}">
        <input type="hidden" name="datacite_test" value="0"><label><input type="checkbox" name="datacite_test" value="1" @checked($settings->exists ? $settings->datacite_test : true)> {{ __('DataCite test service') }}</label>
        <p><button type="submit">{{ __('Save') }}</button></p>
    </form>
@endsection
