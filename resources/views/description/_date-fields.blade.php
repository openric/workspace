{{-- OpenRiC Workspace - the fields of one date. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $prefix }}-property">What the date is</label>
<select id="{{ $prefix }}-property" name="property" required style="font: inherit; padding: .5rem">
    @foreach($choices['properties'] as $concept)<option value="{{ $concept->notation }}" @selected($date?->property_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-normalized">Normalised date (EDTF)</label>
<input id="{{ $prefix }}-normalized" name="normalized" value="{{ $date?->normalized_value }}" maxlength="64" aria-describedby="{{ $prefix }}-normalized-help">
<small id="{{ $prefix }}-normalized-help">For example 1902, 1902-03, 1902-03-15, 1902~ (about 1902), 1902? (probably 1902), 190X (the 1900s), 1902/1950, 1902/.. (from 1902, open), /1950 (start unknown).</small>
<label for="{{ $prefix }}-qualifier">Qualifier</label>
<select id="{{ $prefix }}-qualifier" name="qualifier" style="font: inherit; padding: .5rem">
    <option value="">None</option>
    @foreach($choices['qualifiers'] as $concept)<option value="{{ $concept->notation }}" @selected($date?->qualifier_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-type">OpenRiC date type</label>
<select id="{{ $prefix }}-type" name="type" style="font: inherit; padding: .5rem">
    <option value="">None</option>
    @foreach($choices['types'] as $concept)<option value="{{ $concept->notation }}" @selected($date?->date_type_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-expressed">As written</label>
<input id="{{ $prefix }}-expressed" name="expressed" value="{{ $date?->expressed }}" maxlength="500" aria-describedby="{{ $prefix }}-expressed-help">
<small id="{{ $prefix }}-expressed-help">The date as the description gives it, such as "early 20th century". Give a normalised date, a written form, or both.</small>
<label for="{{ $prefix }}-language">Language of the written form (BCP 47)</label>
<input id="{{ $prefix }}-language" name="language" value="{{ $date?->expressed_language }}" maxlength="35" placeholder="en">
