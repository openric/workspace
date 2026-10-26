{{-- OpenRiC Workspace - the fields of one date. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $prefix }}-property">{{ __('What the date is') }}</label> <small>{{ __('(each choice names its RiC-O property)') }}</small>
<select id="{{ $prefix }}-property" name="property" required style="font: inherit; padding: .5rem">
    @foreach($choices['properties'] as $concept)<option value="{{ $concept->notation }}" @selected($date?->property_id === $concept->id)>{{ $concept->label(\App\Support\Vocab::languages()) }}{{ $concept->ricTerm() ? ' ('.$concept->ricTerm().')' : '' }}</option>@endforeach
</select>
<label for="{{ $prefix }}-normalized">{{ __('Normalised date (EDTF)') }}</label> @include('description._ric', ['term' => 'rico:normalizedDateValue'])
<input id="{{ $prefix }}-normalized" name="normalized" value="{{ $date?->normalized_value }}" maxlength="64" aria-describedby="{{ $prefix }}-normalized-help">
<small id="{{ $prefix }}-normalized-help">{{ __('For example 1902, 1902-03, 1902-03-15, 1902~ (about 1902), 1902? (probably 1902), 190X (the 1900s), 1902/1950, 1902/.. (from 1902, open), /1950 (start unknown).') }}</small>
<label for="{{ $prefix }}-qualifier">{{ __('Qualifier') }}</label> @include('description._ric', ['term' => 'rico:dateQualifier'])
<select id="{{ $prefix }}-qualifier" name="qualifier" style="font: inherit; padding: .5rem">
    <option value="">{{ __('None') }}</option>
    @foreach($choices['qualifiers'] as $concept)<option value="{{ $concept->notation }}" @selected($date?->qualifier_id === $concept->id)>{{ $concept->label(\App\Support\Vocab::languages()) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-type">{{ __('OpenRiC date type') }}</label> @include('description._ric', ['term' => 'rico:hasDateType'])
<select id="{{ $prefix }}-type" name="type" style="font: inherit; padding: .5rem">
    <option value="">{{ __('None') }}</option>
    @foreach($choices['types'] as $concept)<option value="{{ $concept->notation }}" @selected($date?->date_type_id === $concept->id)>{{ $concept->label(\App\Support\Vocab::languages()) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-expressed">{{ __('As written') }}</label> @include('description._ric', ['term' => 'rico:expressedDate'])
<input id="{{ $prefix }}-expressed" name="expressed" value="{{ $date?->expressed }}" maxlength="500" aria-describedby="{{ $prefix }}-expressed-help">
<small id="{{ $prefix }}-expressed-help">{{ __('The date as the description gives it, such as "early 20th century". Give a normalised date, a written form, or both.') }}</small>
<label for="{{ $prefix }}-language">{{ __('Language of the written form (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
<input id="{{ $prefix }}-language" name="language" value="{{ $date?->expressed_language }}" maxlength="35" placeholder="en">
