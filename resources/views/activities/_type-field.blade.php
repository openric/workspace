{{-- OpenRiC Workspace - the activity type field. Needs $types and $current (a concept id or null). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="type">Activity type</label> @include('description._ric', ['term' => 'rico:hasActivityType'])
<select id="type" name="type" style="font: inherit; padding: .5rem" aria-describedby="type-help">
    <option value="">Not set</option>
    @foreach($types->groupBy(fn ($t) => $t->vocabulary->title) as $vocabulary => $inIt)
        <optgroup label="{{ $vocabulary }}">
            @foreach($inIt as $t)<option value="{{ $t->id }}" @selected(old('type', $current) === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
        </optgroup>
    @endforeach
</select>
<small id="type-help">The OpenRiC activity types (production, accumulation, custody, transfer, publication, reproduction), and the institution's own, such as marriage, kept under <a href="{{ route('vocabularies.index') }}">Vocabularies</a> as an activity-type vocabulary.</small>
