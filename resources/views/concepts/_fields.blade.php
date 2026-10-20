{{-- OpenRiC Workspace - what a concept says in one language, and its code and broader concept. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $concept (or null), $language (or null: asked for), $choices (broader concepts), $broader (preselected, or null). --}}
@php($text = $concept?->labels->firstWhere('language', $language))
@php($alts = $concept ? $concept->altLabels->where('language', $language) : collect())
@php($area = 'width: 100%; font: inherit; box-sizing: border-box')
<label for="label">Preferred label (skos:prefLabel){{ $concept ? '; empty removes this language' : '' }}</label>
<input id="label" name="label" maxlength="1024" @unless($concept) required @endunless value="{{ old('label', $text?->pref_label) }}">
<label for="language">Language (BCP 47)</label>
<input id="language" name="language" required maxlength="35" value="{{ old('language', $language ?? 'en') }}">
<label for="alt_labels">Use for: alternative labels, one per line (skos:altLabel)</label>
<textarea id="alt_labels" name="alt_labels" rows="2" style="{{ $area }}">{{ old('alt_labels', $alts->where('is_hidden', false)->pluck('label')->join("\n")) }}</textarea>
<label for="hidden_labels">Hidden labels, found by search but never shown, one per line (skos:hiddenLabel)</label>
<textarea id="hidden_labels" name="hidden_labels" rows="2" style="{{ $area }}">{{ old('hidden_labels', $alts->where('is_hidden', true)->pluck('label')->join("\n")) }}</textarea>
@foreach(['definition' => 'Definition (skos:definition)', 'scope_note' => 'Scope note (skos:scopeNote)', 'history_note' => 'History note (skos:historyNote)', 'source_note' => 'Source note: where the term comes from (dcterms:source)', 'display_note' => 'Display note (skos:note)'] as $note => $noteLabel)
    <label for="{{ $note }}">{{ $noteLabel }}</label>
    <textarea id="{{ $note }}" name="{{ $note }}" rows="2" style="{{ $area }}">{{ old($note, $text?->{$note}) }}</textarea>
@endforeach
<label for="notation">Code (skos:notation, optional)</label>
<input id="notation" name="notation" maxlength="255" value="{{ old('notation', $concept?->notation) }}">
<label for="broader">Broader concept (skos:broader)</label>
<select id="broader" name="broader" style="font: inherit; padding: .5rem">
    <option value="">None: a top concept</option>
    @foreach($choices as $choice)<option value="{{ $choice->id }}" @selected(old('broader', $concept?->broader_id ?? $broader?->id) === $choice->id)>{{ $choice->anyLabel(['en']) }}</option>@endforeach
</select>
<label for="narrower">Add narrower concepts, one per line, in this language</label>
<textarea id="narrower" name="narrower" rows="2" style="{{ $area }}">{{ old('narrower') }}</textarea>
