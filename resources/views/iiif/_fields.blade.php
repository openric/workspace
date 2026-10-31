{{-- OpenRiC Workspace - the fields of a curated IIIF collection (Heratio's iiif_collection). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($c = $collection ?? null)
<p><label for="label">{{ __('Title') }}</label><br><input id="label" name="label" required maxlength="255" size="60" value="{{ old('label', $c?->label) }}"></p>
<p><label for="summary">{{ __('Summary') }}</label><br><textarea id="summary" name="summary" rows="3" cols="60">{{ old('summary', $c?->summary) }}</textarea></p>
<p><label for="language">{{ __('Language of the title and summary (BCP 47)') }}</label> <input id="language" name="language" size="6" value="{{ old('language', $c?->language ?? 'en') }}">
    <label for="nav_date">{{ __('Date for a timeline') }}</label> <input id="nav_date" name="nav_date" type="date" value="{{ old('nav_date', $c?->nav_date?->format('Y-m-d')) }}"></p>
<p><label for="attribution">{{ __('Attribution') }}</label><br><input id="attribution" name="attribution" maxlength="500" size="60" value="{{ old('attribution', $c?->attribution) }}"></p>
<p><label for="behavior">{{ __('How a viewer shows it') }}</label> <select id="behavior" name="behavior"><option value="">{{ __('As the viewer chooses') }}</option>
    @foreach($behaviors as $b)<option value="{{ $b->notation }}" @selected(old('behavior', $c?->behavior?->notation) === $b->notation)>{{ $b->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select></p>
@isset($others)
<p><label for="parent_id">{{ __('Inside the collection') }}</label> <select id="parent_id" name="parent_id"><option value="">{{ __('None: at the top') }}</option>
    @foreach($others as $o)<option value="{{ $o->id }}" @selected(old('parent_id', $c?->parent_id) === $o->id)>{{ $o->label }}</option>@endforeach</select></p>
@endisset
<p><label><input type="hidden" name="is_public" value="0"><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $c?->is_public ?? true))> {{ __('Public: published as a IIIF collection, with the records in it the public may see') }}</label></p>
