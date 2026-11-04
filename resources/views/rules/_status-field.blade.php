{{-- OpenRiC Workspace - a rule's status (roadmap step 12d). Needs $current (a notation or null); $ruleStatuses. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="status">{{ __('Status') }}</label> @include('description._ric', ['term' => 'openricx:hasRuleStatus'])
<select id="status" name="status" style="font: inherit; padding: .5rem">
    <option value="">{{ __('Not set') }}</option>
    @foreach($ruleStatuses as $s)<option value="{{ $s->notation }}" @selected($current === $s->notation)>{{ $s->label(\App\Support\Vocab::languages()) }}</option>@endforeach
</select>
