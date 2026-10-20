{{-- OpenRiC Workspace - the fields of one name. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $prefix }}-type">Form of name</label>
<select id="{{ $prefix }}-type" name="type" required style="font: inherit; padding: .5rem">
    @foreach($nameTypes as $concept)<option value="{{ $concept->notation }}" @selected($name?->name_type_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-value">Name</label>
<input id="{{ $prefix }}-value" name="value" value="{{ $name?->value }}" required maxlength="2000">
<label for="{{ $prefix }}-language">Language (BCP 47)</label>
<input id="{{ $prefix }}-language" name="language" value="{{ $name?->language }}" required maxlength="35" placeholder="en">
<label for="{{ $prefix }}-from">Used from (EDTF, optional)</label>
<input id="{{ $prefix }}-from" name="used_from" value="{{ $from }}" maxlength="64" aria-describedby="{{ $prefix }}-used-help">
<label for="{{ $prefix }}-to">Used to (EDTF, optional)</label>
<input id="{{ $prefix }}-to" name="used_to" value="{{ $to }}" maxlength="64" aria-describedby="{{ $prefix }}-used-help">
<small id="{{ $prefix }}-used-help">For example 1902, 1902-03 or 1902-03-15; 1902~ for about 1902.</small>
