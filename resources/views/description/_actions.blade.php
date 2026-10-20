{{-- OpenRiC Workspace - edit and translate actions for a description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject, $editRoute (route name taking [subject, language]) and $required (the mandatory element). --}}
<div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; margin: 1rem 0">
    <a href="{{ route($editRoute, [$subject, 'language' => $subject->originalLanguage($required)]) }}" style="display: inline-block; padding: .5rem 1rem; background: var(--accent); color: var(--bg); text-decoration: none">Edit description</a>
    <form method="get" action="{{ route($editRoute, $subject) }}" style="display: flex; gap: .5rem; align-items: end">
        <div>
            <label for="translate-language" style="margin-top: 0">Add a translation in (BCP 47, e.g. af, fr)</label>
            <input id="translate-language" name="language" required pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8})*" style="max-width: 8rem">
        </div>
        <button type="submit" style="margin-top: 0">Translate</button>
    </form>
</div>
@php($languages = $subject->languages())
@if(count($languages) > 1)
    <p>Described in: @foreach($languages as $lang)<a href="{{ route($editRoute, [$subject, 'language' => $lang]) }}">{{ $lang }}</a>@if(! $loop->last), @endif @endforeach</p>
@endif
