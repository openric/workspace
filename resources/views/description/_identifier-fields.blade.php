{{-- OpenRiC Workspace - the fields of one identifier. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $prefix }}-type">Type</label> @include('description._ric', ['term' => 'rico:hasIdentifierType'])
<select id="{{ $prefix }}-type" name="type" required style="font: inherit; padding: .5rem">
    @foreach($identifierTypes as $concept)<option value="{{ $concept->notation }}" @selected($identifier?->identifier_type_id === $concept->id)>{{ $concept->label(['en', 'und']) }}</option>@endforeach
</select>
<label for="{{ $prefix }}-value">Identifier</label> @include('description._ric', ['term' => 'rico:textualValue'])
<input id="{{ $prefix }}-value" name="value" value="{{ $identifier?->value }}" required maxlength="255">
