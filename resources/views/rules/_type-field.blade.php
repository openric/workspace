{{-- OpenRiC Workspace - the rule type field. Needs $types and $current (a concept id or null). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="type">{{ __('Rule type') }}</label> @include('description._ric', ['term' => 'rico:hasOrHadRuleType'])
<select id="type" name="type" style="font: inherit; padding: .5rem" aria-describedby="type-help">
    <option value="">{{ __('Not set') }}</option>
    @foreach($types->groupBy(fn ($t) => $t->vocabulary->title) as $vocabulary => $inIt)
        <optgroup label="{{ $vocabulary }}">
            @foreach($inIt as $t)<option value="{{ $t->id }}" @selected(old('type', $current) === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
        </optgroup>
    @endforeach
</select>
<small id="type-help">{{ __('The OpenRiC rule types (law, regulation, mandate, policy, custom), and the institution\'s own, kept under') }} <a href="{{ route('vocabularies.index') }}">{{ __('Taxonomies') }}</a> {{ __('as a rule-type vocabulary. A rule of type mandate is a rico:Mandate and may give an agent its authority.') }}</small>
