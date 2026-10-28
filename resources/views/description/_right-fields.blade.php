{{-- OpenRiC Workspace - the fields of a right (Heratio's rights edit form, ahg-extended-rights). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs: $right (or null), $prefix (unique id prefix), $choices (vocabulary IRI => concepts). Every list is a vocabulary. --}}
@php($lang = \App\Support\Vocab::languages())
@php($select = function (string $name, string $vocabulary, ?\App\Models\Concept $current, bool $optional) use ($choices, $lang, $prefix) {
    $html = '<select id="'.e($prefix.'-'.$name).'" name="'.e($name).'">'.($optional ? '<option value="">'.e(__('Not given')).'</option>' : '');
    foreach ($choices($vocabulary) as $concept) {
        $html .= '<option value="'.e($concept->notation).'"'.(old($name, $current?->notation) === $concept->notation ? ' selected' : '').'>'.e($concept->label($lang)).'</option>';
    }

    return new \Illuminate\Support\HtmlString($html.'</select>');
})
<label for="{{ $prefix }}-basis">{{ __('Basis') }}</label>{{ $select('basis', \App\Rights\RightsService::BASES, $right?->basis, false) }}
<label for="{{ $prefix }}-statement">{{ __('Rights statement') }} (RightsStatements.org)</label>{{ $select('statement', \App\Rights\RightsService::STATEMENTS, $right?->statement, true) }}
<label for="{{ $prefix }}-licence">{{ __('Licence') }} (Creative Commons)</label>{{ $select('licence', \App\Rights\RightsService::LICENCES, $right?->licence, true) }}
<label for="{{ $prefix }}-copyright_status">{{ __('Copyright status') }}</label>{{ $select('copyright_status', \App\Rights\RightsService::COPYRIGHT_STATUSES, $right?->copyrightStatus, true) }}
<label for="{{ $prefix }}-holder">{{ __('Rights holder') }} @include('description._ric', ['term' => 'rico:hasOrHadIntellectualPropertyRightsHolder'])</label>
{{-- ponytail: every authority record in one list; a search field when institutions hold more than a few thousand. --}}
<select id="{{ $prefix }}-holder" name="holder"><option value="">{{ __('Not given') }}</option>
    @foreach(\App\Models\Agent::where('institution_id', auth()->user()->institution_id)->whereNull('deleted_at')->with('names')->get()->sortBy(fn ($a) => mb_strtolower((string) $a->described('agent-authorised-name', ['en']))) as $agent)
        <option value="{{ $agent->id }}" @selected(old('holder', $right?->holder_id) === $agent->id)>{{ $agent->described('agent-authorised-name', $lang) }}</option>
    @endforeach
</select>
<label for="{{ $prefix }}-jurisdiction">{{ __('Jurisdiction (two-letter country code, such as ZA)') }}</label><input id="{{ $prefix }}-jurisdiction" name="jurisdiction" maxlength="2" size="3" value="{{ old('jurisdiction', $right?->jurisdiction) }}">
<label for="{{ $prefix }}-determined_on">{{ __('Status determined on') }}</label><input id="{{ $prefix }}-determined_on" name="determined_on" type="date" value="{{ old('determined_on', $right?->determined_on?->toDateString()) }}">
<label for="{{ $prefix }}-starts_on">{{ __('In force from') }}</label><input id="{{ $prefix }}-starts_on" name="starts_on" type="date" value="{{ old('starts_on', $right?->starts_on?->toDateString()) }}">
<label for="{{ $prefix }}-ends_on">{{ __('In force until') }}</label><input id="{{ $prefix }}-ends_on" name="ends_on" type="date" value="{{ old('ends_on', $right?->ends_on?->toDateString()) }}">
<label for="{{ $prefix }}-citation">{{ __('Citation') }}</label><input id="{{ $prefix }}-citation" name="citation" value="{{ old('citation', $right?->citation) }}">
<label for="{{ $prefix }}-licence_terms">{{ __('Licence terms') }}</label><textarea id="{{ $prefix }}-licence_terms" name="licence_terms" rows="2" style="width: 100%; font: inherit">{{ old('licence_terms', $right?->licence_terms) }}</textarea>
<label for="{{ $prefix }}-policy_identifier">{{ __('Policy identifier') }}</label><input id="{{ $prefix }}-policy_identifier" name="policy_identifier" value="{{ old('policy_identifier', $right?->policy_identifier) }}">
<fieldset>
    <legend>{{ __('What it allows') }}</legend>
    @php($grants = $right?->grants->keyBy(fn ($g) => $g->act->notation) ?? collect())
    @foreach($choices(\App\Rights\RightsService::ACTS) as $act)
        <div style="display: flex; gap: .5rem; align-items: center; flex-wrap: wrap">
            <label for="{{ $prefix }}-grant-{{ $act->notation }}" style="min-width: 8rem; margin: 0">{{ $act->label($lang) }}</label>
            <select id="{{ $prefix }}-grant-{{ $act->notation }}" name="grants[{{ $act->notation }}][restriction]" style="width: auto"><option value="">{{ __('Not said') }}</option>
                @foreach($choices(\App\Rights\RightsService::RESTRICTIONS) as $restriction)
                    <option value="{{ $restriction->notation }}" @selected(old("grants.{$act->notation}.restriction", $grants->get($act->notation)?->restriction->notation) === $restriction->notation)>{{ $restriction->label($lang) }}</option>
                @endforeach
            </select>
            <input name="grants[{{ $act->notation }}][note]" aria-label="{{ __('Condition or note for :act', ['act' => $act->label($lang)]) }}" placeholder="{{ __('Condition or note') }}" value="{{ old("grants.{$act->notation}.note", $grants->get($act->notation)?->note) }}" style="flex: 1; min-width: 12rem">
        </div>
    @endforeach
</fieldset>
<label for="{{ $prefix }}-note">{{ __('Note') }}</label><textarea id="{{ $prefix }}-note" name="note" rows="2">{{ old('note', $right?->note) }}</textarea>
<label for="{{ $prefix }}-note_language">{{ __('Language of the note (BCP 47)') }}</label><input id="{{ $prefix }}-note_language" name="note_language" size="8" value="{{ old('note_language', $right?->note_language ?? 'en') }}">
