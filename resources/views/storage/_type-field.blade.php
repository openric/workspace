{{-- OpenRiC Workspace - the container type field. Needs $types and $current (a concept id or null). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="type">Container type</label> @include('description._ric', ['term' => 'openricx:hasContainerType'])
<select id="type" name="type" style="font: inherit; padding: .5rem" aria-describedby="type-help">
    <option value="">Not set</option>
    @foreach($types->groupBy(fn ($t) => $t->vocabulary->title) as $vocabulary => $inIt)
        <optgroup label="{{ $vocabulary }}">
            @foreach($inIt as $t)<option value="{{ $t->id }}" @selected(old('type', $current) === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
        </optgroup>
    @endforeach
</select>
<small id="type-help">The container types (box, folder, binder, map drawer, crate, canister), and the institution's own, kept under <a href="{{ route('vocabularies.index') }}">Vocabularies</a> as a container-type vocabulary.</small>
