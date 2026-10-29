{{-- OpenRiC Workspace - a repository's theme: background colour, banner, logo and page content (after AtoM's and Heratio's edit-theme). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Theme'))
@section('content')
    @php($name = $repository->described('authorised-name', [app()->getLocale(), 'en']))
    <p><a href="{{ route('repositories.show', $repository) }}">{{ __('Back to the repository') }}</a></p>
    <h1>{{ __('Theme of :name', ['name' => $name]) }}</h1>
    <p>{{ __('How the repository\'s page looks in the public catalogue.') }}</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('repositories.theme.update', $repository) }}" enctype="multipart/form-data">
        @csrf
        <h2>{{ __('Style') }}</h2>
        <label for="background_colour">{{ __('Background colour') }}</label>
        <input id="background_colour" name="background_colour" type="color" style="width: 5rem; height: 2.5rem" value="{{ old('background_colour', $repository->theme_background_colour ?? '#ffffff') }}">
        <label style="font-weight: normal"><input type="checkbox" name="no_background" value="1" style="width: auto" @checked($repository->theme_background_colour === null) onchange="document.getElementById('background_colour').disabled = this.checked"> {{ __('No background colour') }}</label>
        @foreach(['banner' => __('Banner'), 'logo' => __('Logo')] as $part => $label)
            <label for="{{ $part }}">{{ $label }}</label>
            @if($repository->{"theme_{$part}_path"})
                <p><img src="{{ route('repositories.theme.image', [$repository, $part]) }}" alt="" style="max-height: 6rem; max-width: 100%"></p>
                <label style="font-weight: normal"><input type="hidden" name="remove_{{ $part }}" value="0"><input type="checkbox" name="remove_{{ $part }}" value="1" style="width: auto"> {{ __('Remove it') }}</label>
            @endif
            <input id="{{ $part }}" name="{{ $part }}" type="file" accept="image/jpeg,image/png,image/webp">
        @endforeach
        <small>{{ __('JPEG, PNG or WebP, up to 5 MB.') }}</small>
        <h2>{{ __('Page content') }}</h2>
        <label for="content">{{ __('Description') }}</label>
        <small id="content-help">{{ __('Shown below the banner and above the repository\'s description, such as a summary of its mandate, a tag line or important information. Markdown can style it; HTML is removed.') }}</small>
        <textarea id="content" name="content" rows="8" maxlength="20000" aria-describedby="content-help">{{ old('content', $repository->theme_content) }}</textarea>
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
