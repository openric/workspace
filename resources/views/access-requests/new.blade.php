{{-- OpenRiC Workspace - a general request for access: everything, a collection or an item (after Heratio's ahg-access-request new). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('New access request'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('access-requests.mine') }}">{{ __('Back to My access requests') }}</a></p>
    <h1>{{ __('New access request') }}</h1>
    <p>{{ __('Ask for access to material available on request. Someone who reviews access requests will approve your request for a time, or refuse it with a reason. You will have an email either way.') }}</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('access-requests.store-general') }}">
        @csrf
        <label for="subject">{{ __('Subject') }}</label>
        <input id="subject" name="subject" required maxlength="255" value="{{ old('subject') }}">
        <label for="request_type">{{ __('Request type') }}</label>
        <select id="request_type" name="request_type" required>
            <option value="">{{ __('Choose') }}</option>
            @foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('request_type') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach
        </select>
        <fieldset>
            <legend>{{ __('What do you need access to?') }}</legend>
            <label><input type="radio" name="scope" value="all" @checked($scope === 'all')> {{ __('Everything') }}</label>
            <small>{{ __('All holdings available on request that you may see.') }}</small>
            <label><input type="radio" name="scope" value="collection" @checked($scope === 'collection')> {{ __('A collection') }}</label>
            <small>{{ __('One collection and everything catalogued inside it.') }}</small>
            @if($collections->isNotEmpty())
                <select name="collection" aria-label="{{ __('Collection') }}">
                    <option value="">{{ __('Choose a collection') }}</option>
                    @foreach($collections as $c)<option value="{{ $c->id }}" @selected($chosen === $c->id)>{{ $c->described('title', [app()->getLocale(), 'en']) }} ({{ $c->level->label($lang) }})</option>@endforeach
                </select>
            @else
                <small>{{ __('No collection-level records were found, so this option has nothing to offer. Request a single item or everything instead.') }}</small>
            @endif
            @if($item)
                <label><input type="radio" name="scope" value="item" @checked($scope === 'item')> {{ __('A single item') }}: {{ $item->described('title', [app()->getLocale(), 'en']) }}</label>
                <input type="hidden" name="item" value="{{ $item->id }}">
                <small>{{ __('One record only - its children are not included.') }}</small>
            @else
                <small>{{ __('To ask for a single item, open its record and choose Ask for access.') }}</small>
            @endif
        </fieldset>
        <label for="description">{{ __('Description') }}</label>
        <small id="description-help">{{ __('What you need and what for, such as the research it serves.') }}</small>
        <textarea id="description" name="description" rows="5" required maxlength="4000" aria-describedby="description-help">{{ old('description') }}</textarea>
        <label for="justification">{{ __('Justification (optional)') }}</label>
        <small id="justification-help">{{ __('Anything else the reviewer should know, such as a permission you hold.') }}</small>
        <textarea id="justification" name="justification" rows="3" maxlength="4000" aria-describedby="justification-help">{{ old('justification') }}</textarea>
        <label for="urgency">{{ __('Urgency') }}</label>
        <select id="urgency" name="urgency">
            @foreach($urgencies as $u)<option value="{{ $u->notation }}" @selected(old('urgency', 'normal') === $u->notation)>{{ $u->label($lang) }}: {{ $u->labels->firstWhere('language', 'en')?->definition }}</option>@endforeach
        </select>
        <button type="submit">{{ __('Send the request') }}</button>
    </form>
@endsection
