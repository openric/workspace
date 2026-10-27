{{-- OpenRiC Workspace - the institution's watermark (roadmap phase 5; after Heratio's ahg-media-processing watermark settings). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Watermark'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('settings.edit') }}">{{ __('Back to Settings') }}</a></p>
    <h1>{{ __('Watermark') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('The watermark is written on the reference copy of each image, the copy the catalogue and the viewer show. The master, as received, is never marked, nor the thumbnail.') }}</p>
    <form method="post" action="{{ route('watermark.update') }}" enctype="multipart/form-data">
        @csrf
        <label for="type">{{ __('Watermark') }}</label>
        <select id="type" name="type">
            <option value="">{{ __('None') }}</option>
            @foreach($types as $type)<option value="{{ $type->notation }}" @selected(old('type', $institution->watermarkType?->notation) === $type->notation)>{{ $type->label($lang) }}: {{ $type->labels->firstWhere('language', 'en')?->definition }}</option>@endforeach
        </select>
        <label for="image">{{ __('Custom image (PNG, at most 1 MB)') }}</label>
        <small id="image-help">{{ __('Used when the watermark is a custom image. A PNG with a transparent background shows best.') }}</small>
        @if($institution->watermark_image_path)<p><small>{{ __('An image is uploaded; upload another to replace it.') }}</small></p>@endif
        <input id="image" name="image" type="file" accept="image/png" aria-describedby="image-help">
        <label for="position">{{ __('Position') }}</label>
        <select id="position" name="position">
            @foreach($positions as $position)<option value="{{ $position->notation }}" @selected(old('position', $institution->watermarkPosition?->notation ?? 'center') === $position->notation)>{{ $position->label($lang) }}</option>@endforeach
        </select>
        <label for="opacity">{{ __('Opacity (per cent)') }}</label>
        <input id="opacity" name="opacity" type="number" min="1" max="100" required value="{{ old('opacity', $institution->watermark_opacity) }}">
        <label for="min_size">{{ __('Minimum image size (pixels)') }}</label>
        <small id="size-help">{{ __('An image smaller than this on either side is not marked.') }}</small>
        <input id="min_size" name="min_size" type="number" min="0" max="10000" required aria-describedby="size-help" value="{{ old('min_size', $institution->watermark_min_size) }}">
        <button type="submit">{{ __('Save the watermark') }}</button>
    </form>
    <h2>{{ __('Images already kept') }}</h2>
    <p>{{ __('A change applies to images uploaded from now on. To mark, or unmark, the images already kept, make their viewing copies again; it runs in the background, and Jobs shows how far it is.') }}</p>
    <form method="post" action="{{ route('watermark.remake-all') }}">@csrf<button type="submit" onclick="return confirm(@js(__('Make the viewing copies of every image again?')))">{{ __('Make the viewing copies of every image again') }}</button></form>
@endsection
