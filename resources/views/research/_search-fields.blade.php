{{-- OpenRiC Workspace - the fields of a saved search (Heratio's research_saved_search). Needs $s (or null), $p, and a query and filters for a new one. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<p><label for="{{ $p }}-name">{{ __('Name') }}</label> <input id="{{ $p }}-name" name="name" required value="{{ $s?->name }}">
    <label for="{{ $p }}-query">{{ __('Words') }}</label> <input id="{{ $p }}-query" name="search_query" required value="{{ $s?->search_query ?? ($query ?? '') }}">
    @foreach((array) ($s?->search_filters ?? ($filters ?? [])) as $k => $v)@if($v !== null && $v !== '')<input type="hidden" name="search_filters[{{ $k }}]" value="{{ $v }}">@endif @endforeach
    <label for="{{ $p }}-description">{{ __('Description') }}</label> <input id="{{ $p }}-description" name="description" size="30" value="{{ $s?->description }}"></p>
<p><label><input type="hidden" name="alert_enabled" value="0"><input type="checkbox" name="alert_enabled" value="1" @checked($s?->alert_enabled)> {{ __('Tell me of new results') }}</label>
    <select name="alert_frequency" aria-label="{{ __('How often') }}">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'alert-frequency/')->get() as $f)<option value="{{ $f->notation }}" @selected(($s?->alertFrequency?->notation ?? 'weekly') === $f->notation)>{{ $f->label($lang) }}</option>@endforeach</select></p>
