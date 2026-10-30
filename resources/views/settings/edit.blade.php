{{-- OpenRiC Workspace - institution settings. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Settings'))
@section('content')
    <h1>{{ __('Settings') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('settings.update') }}" enctype="multipart/form-data">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">{{ __('Name of the institution') }}</label>
        <input id="name" name="name" required value="{{ old('name', $institution->name) }}">
        <label for="header_title">{{ __('Text in the header of every page') }}</label>
        <small id="header-help">{{ __('Leave empty to show ":name".', ['name' => config('app.name')]) }}</small>
        <input id="header_title" name="header_title" aria-describedby="header-help" value="{{ old('header_title', $institution->header_title) }}">
        <label for="logo">{{ __('Logo (PNG, JPEG or WebP, at most 1 MB)') }}</label>
        @if($institution->logo_path)<p><img src="{{ route('branding.logo') }}?v={{ md5($institution->logo_path) }}" alt="{{ __('Current logo') }}" style="max-height: 64px"></p>@endif
        <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
        @if($institution->logo_path)
            <label style="font-weight: normal"><input type="checkbox" name="remove_logo" value="1" style="width: auto"> {{ __('Remove the logo') }}</label>
        @endif
        <h2>{{ __('Public catalogue') }}</h2>
        <label for="catalogue_heading">{{ __('Heading on the catalogue\'s home page') }}</label>
        <small id="heading-help">{{ __('Empty: "The catalogue of" and the name of the institution.') }}</small>
        <input id="catalogue_heading" name="catalogue_heading" maxlength="255" aria-describedby="heading-help" value="{{ old('catalogue_heading', $institution->catalogue_heading) }}">
        <label for="catalogue_introduction">{{ __('Introduction on the catalogue\'s home page') }}</label>
        <small id="intro-help">{{ __('What the catalogue and the institution offer, shown first on the home page. Plain text: an empty line starts a new paragraph, and a web address becomes a link.') }}</small>
        <textarea id="catalogue_introduction" name="catalogue_introduction" rows="8" aria-describedby="intro-help">{{ old('catalogue_introduction', $institution->catalogue_introduction) }}</textarea>
        <input type="hidden" name="catalogue_show_counts" value="0">
        <label style="font-weight: normal"><input type="checkbox" name="catalogue_show_counts" value="1" @checked(old('catalogue_show_counts', $institution->catalogue_show_counts)) style="width: auto"> {{ __('Show the counts of what is published under the introduction') }}</label>
        <label for="catalogue_credits">{{ __('Credits for data reused from others') }}</label>
        <small id="credits-help">{{ __('Shown on the catalogue\'s home page. Many open-data licences require a credit with each reuse: give the author, the dataset, its version and date, and a link.') }}</small>
        <textarea id="catalogue_credits" name="catalogue_credits" rows="6" aria-describedby="credits-help">{{ old('catalogue_credits', $institution->catalogue_credits) }}</textarea>
        <h2>{{ __('Images') }}</h2>
        <p><a href="{{ route('watermark.edit') }}">{{ __('Watermark on the reference copies of images') }}</a></p>
        <h2>{{ __('Users') }}</h2>
        <label style="font-weight: normal"><input type="hidden" name="registration_open" value="0"><input type="checkbox" name="registration_open" value="1" style="width: auto" @checked(old('registration_open', $institution->registration_open))> {{ __('Visitors may ask for an account; an administrator approves or rejects each request under Accounts') }}</label>
        <h2>{{ __('Interface languages') }}</h2>
        <p><small>{{ __('The languages people may choose for the screens of the Workspace and the public catalogue. English is always offered; a string with no translation in a language is shown in English.') }}</small></p>
        @php($chosen = old('interface_languages', $institution->interface_languages ?? ['en']))
        @php($available = \App\Support\InterfaceLanguages::available())
        <input type="hidden" name="interface_languages[]" value="en">
        <label for="interface_languages">{{ __('Interface languages') }}</label>
        <select id="interface_languages" name="interface_languages[]" multiple size="{{ min(count($available), 10) }}" aria-describedby="languages-help">
            @foreach($available as $tag)
                <option value="{{ $tag }}" lang="{{ $tag }}" @selected($tag === 'en' || in_array($tag, $chosen, true)) @disabled($tag === 'en')>{{ \App\Support\InterfaceLanguages::name($tag) }}</option>
            @endforeach
        </select>
        <small id="languages-help">{{ __('Hold Ctrl (Cmd on a Mac) to choose more than one.') }}</small>
        <p><small>{{ __('The persistent IRIs of records are built from :iri, which is set at installation and does not change.', ['iri' => $institution->base_iri]) }}</small></p>
        <button type="submit">{{ __('Save settings') }}</button>
    </form>
@endsection
