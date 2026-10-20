{{-- OpenRiC Workspace - what is said about one relation. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $prefix, $relation (or null), $withRole and $choices (RelationController::choices); $type, the relation type's notation, for the RiC term of the role. --}}
@if($withRole)
<label for="{{ $prefix }}-role">Role of the agent (optional)</label> @include('description._ric', ['term' => ($type ?? null) === 'creation' ? 'rico:creationWithRole' : null])
<select id="{{ $prefix }}-role" name="role" style="font: inherit; padding: .5rem">
    <option value="">None</option>
    @foreach($choices['roles'] as $concept)<option value="{{ $concept->notation }}" @selected($relation?->role_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
@endif
<label for="{{ $prefix }}-from">From (EDTF, optional)</label> @include('description._ric', ['term' => 'rico:hasBeginningDate'])
<input id="{{ $prefix }}-from" name="from" value="{{ $relation?->datedAs('beginning')?->normalized_value }}" maxlength="64" aria-describedby="{{ $prefix }}-dates-help">
<label for="{{ $prefix }}-to">To (EDTF, optional)</label> @include('description._ric', ['term' => 'rico:hasEndDate'])
<input id="{{ $prefix }}-to" name="to" value="{{ $relation?->datedAs('end')?->normalized_value }}" maxlength="64" aria-describedby="{{ $prefix }}-dates-help">
<small id="{{ $prefix }}-dates-help">For example 1902, 1902-03-15, 1902~ (about 1902) or 190X (the 1900s).</small>
<label for="{{ $prefix }}-state">State</label> @include('description._ric', ['term' => 'rico:relationState'])
<select id="{{ $prefix }}-state" name="state" style="font: inherit; padding: .5rem">
    <option value="">Not given</option>
    @foreach($choices['states'] as $concept)<option value="{{ $concept->notation }}" @selected($relation?->state_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-certainty">Certainty</label> @include('description._ric', ['term' => 'rico:relationCertainty'])
<select id="{{ $prefix }}-certainty" name="certainty" style="font: inherit; padding: .5rem">
    <option value="">Not given</option>
    @foreach($choices['certainties'] as $concept)<option value="{{ $concept->notation }}" @selected($relation?->certainty_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-description">Description of the relation (optional)</label> @include('description._ric', ['term' => 'rico:generalDescription'])
<textarea id="{{ $prefix }}-description" name="description" rows="2" style="width: 100%; font: inherit; box-sizing: border-box">{{ $relation?->description }}</textarea>
<label for="{{ $prefix }}-source">Source of information (optional)</label> @include('description._ric', ['term' => 'rico:relationSource'])
<input id="{{ $prefix }}-source" name="source_of_information" value="{{ $relation?->source_of_information }}" maxlength="5000">
<label for="{{ $prefix }}-language">Language of the description and source (BCP 47)</label> @include('description._ric', ['term' => 'xml:lang'])
<input id="{{ $prefix }}-language" name="language" value="{{ $relation?->language }}" maxlength="35" placeholder="en">
