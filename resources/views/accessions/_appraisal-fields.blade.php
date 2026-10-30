{{-- OpenRiC Workspace - the fields of an appraisal (PSIS's and Heratio's appraisal form). Needs $a (or null), $p (id prefix), $care, $select. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $p }}-type">{{ __('Type') }}</label>{{ $select('type', $care['types'], $a?->type->notation ?? 'archival', false, $p.'-type') }}
<label for="{{ $p }}-significance">{{ __('Significance') }}</label>{{ $select('significance', $care['significance'], $a?->significance?->notation, true, $p.'-significance') }}
<label for="{{ $p }}-recommendation">{{ __('Recommendation') }}</label>{{ $select('recommendation', $care['recommendations'], $a?->recommendation->notation ?? 'pending', false, $p.'-recommendation') }}
<label for="{{ $p }}-appraiser">{{ __('Appraiser') }}</label>
{{-- ponytail: every authority record in one list, as the rights holder; a search field when institutions hold more than a few thousand. --}}
<select id="{{ $p }}-appraiser" name="appraiser" style="font: inherit; padding: .5rem"><option value="">{{ __('Not given') }}</option>
    @foreach($care['agents'] as $agent)<option value="{{ $agent->id }}" @selected($a?->appraiser_id === $agent->id)>{{ $agent->described('agent-authorised-name', \App\Support\Vocab::languages()) }}</option>@endforeach
</select>
<div style="display: flex; gap: .75rem; flex-wrap: wrap">
    <div><label for="{{ $p }}-value">{{ __('Monetary value') }}</label><input id="{{ $p }}-value" name="monetary_value" inputmode="decimal" style="max-width: 10rem" value="{{ $a?->monetary_value }}"></div>
    <div><label for="{{ $p }}-currency">{{ __('Currency') }}</label><input id="{{ $p }}-currency" name="currency" maxlength="3" style="max-width: 5rem" value="{{ $a?->currency ?? 'ZAR' }}"></div>
    <div><label for="{{ $p }}-date">{{ __('Appraised on') }}</label><input id="{{ $p }}-date" name="appraised_on" type="date" value="{{ $a?->appraised_on?->format('Y-m-d') ?? now()->toDateString() }}"></div>
</div>
<label for="{{ $p }}-summary">{{ __('Summary') }}</label><textarea id="{{ $p }}-summary" name="summary" rows="2" style="width: 100%; font: inherit">{{ $a?->summary }}</textarea>
<label for="{{ $p }}-notes">{{ __('Notes') }}</label><textarea id="{{ $p }}-notes" name="detailed_notes" rows="3" style="width: 100%; font: inherit">{{ $a?->detailed_notes }}</textarea>
