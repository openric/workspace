{{-- OpenRiC Workspace - edit and translate actions for a description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject, $editRoute (route name taking [subject, language]) and $required (the mandatory element). Offers Delete when the account may delete it. --}}
{{-- Phase 26: the button types - Edit primary, Publication secondary, History tertiary, Delete danger; Translate opens its own small form. --}}
<div class="actions" style="margin: 0">
    <a href="{{ route($editRoute, [$subject, 'language' => $subject->originalLanguage($required)]) }}" class="button">{{ __('Edit description') }}</a>
    @php($deletable = \App\Relations\EntityKinds::get(\App\Relations\EntityKinds::of($subject)))
    {{-- A container is never published: storage is not for the public. --}}
    @if(! $subject instanceof \App\Models\Container && (auth()->user()->may('publish', $deletable['permission'], $subject) || auth()->user()->may('update', $deletable['permission'], $subject)))
        <a href="{{ route('publication.edit', [$deletable['segment'], $subject->getKey()]) }}" class="button-secondary">{{ __('Publication') }}</a>
    @endif
    @if(auth()->user()->may('update', $deletable['permission'], $subject) && ! $subject instanceof \App\Models\RecordResource)
        <a href="{{ route('history', [$deletable['segment'], $subject->getKey()]) }}" class="button-tertiary">{{ __('History') }}</a>
    @endif
    @if(auth()->user()->may('delete', $deletable['permission'], $subject))
        <a href="{{ route('deletion.confirm', [$deletable['segment'], $subject->getKey()]) }}" class="button-secondary button-danger">{{ __('Delete') }}</a>
    @endif
    <details class="more">
        <summary class="button-tertiary">{{ __('Translate') }}</summary>
        <ul><li style="padding: .5rem .75rem !important"><form method="get" action="{{ route($editRoute, $subject) }}">
            <label for="translate-language" style="margin-top: 0">{{ __('Add a translation in (BCP 47, e.g. af, fr)') }}</label>
            <input id="translate-language" name="language" required pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8})*" style="max-width: 8rem">
            <button type="submit" class="button" style="margin-top: .5rem">{{ __('Translate') }}</button>
        </form></li></ul>
    </details>
</div>
@php($languages = $subject->languages())
@if(count($languages) > 1)
    <p>{{ __('Described in:') }} @foreach($languages as $lang)<a href="{{ route($editRoute, [$subject, 'language' => $lang]) }}">{{ $lang }}</a>@if(! $loop->last), @endif @endforeach</p>
@endif
