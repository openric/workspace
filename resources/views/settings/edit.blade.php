{{-- OpenRiC Workspace - institution settings. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Settings')
@section('content')
    <h1>Settings</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('settings.update') }}" enctype="multipart/form-data">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">Name of the institution</label>
        <input id="name" name="name" required value="{{ old('name', $institution->name) }}">
        <label for="header_title">Text in the header of every page</label>
        <small id="header-help">Leave empty to show "{{ config('app.name') }}".</small>
        <input id="header_title" name="header_title" aria-describedby="header-help" value="{{ old('header_title', $institution->header_title) }}">
        <label for="logo">Logo (PNG, JPEG or WebP, at most 1 MB)</label>
        @if($institution->logo_path)<p><img src="{{ route('branding.logo') }}?v={{ md5($institution->logo_path) }}" alt="Current logo" style="max-height: 64px"></p>@endif
        <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
        @if($institution->logo_path)
            <label style="font-weight: normal"><input type="checkbox" name="remove_logo" value="1" style="width: auto"> Remove the logo</label>
        @endif
        <h2>Public catalogue</h2>
        <label for="catalogue_introduction">Introduction on the catalogue's home page</label>
        <small id="intro-help">What the catalogue and the institution offer, shown first on the home page. Plain text: an empty line starts a new paragraph, and a web address becomes a link.</small>
        <textarea id="catalogue_introduction" name="catalogue_introduction" rows="8" aria-describedby="intro-help">{{ old('catalogue_introduction', $institution->catalogue_introduction) }}</textarea>
        <label for="catalogue_credits">Credits for data reused from others</label>
        <small id="credits-help">Shown on the catalogue's home page. Many open-data licences require a credit with each reuse: give the author, the dataset, its version and date, and a link.</small>
        <textarea id="catalogue_credits" name="catalogue_credits" rows="6" aria-describedby="credits-help">{{ old('catalogue_credits', $institution->catalogue_credits) }}</textarea>
        <p><small>The persistent IRIs of records are built from {{ $institution->base_iri }}, which is set at installation and does not change.</small></p>
        <button type="submit">Save settings</button>
    </form>
@endsection
