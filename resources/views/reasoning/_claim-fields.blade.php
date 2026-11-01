{{-- OpenRiC Workspace - a claim's fields: the claim, its assertion, status and confidence, and the claim ledger's own (Heratio's claim ledger and assertions; roadmap phase 19i). Needs $c (or null), $x and the choices. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-text">{{ __('The claim') }}</label><br><textarea id="{{ $x }}-text" name="object_value" rows="3" cols="80" required>{{ old('object_value', $c?->object_value) }}</textarea><br>
<label for="{{ $x }}-type">{{ __('Kind') }}</label> <select id="{{ $x }}-type" name="claim_type">@foreach($types as $k)<option value="{{ $k->notation }}" @selected(old('claim_type', $c?->claimType->notation ?? 'claim') === $k->notation)>{{ $k->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">@foreach($statuses as $k)<option value="{{ $k->notation }}" @selected(old('status', $c?->status->notation ?? 'idea') === $k->notation)>{{ $k->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-level">{{ __('Confidence') }}</label> <select id="{{ $x }}-level" name="confidence_level"><option value="">{{ __('None') }}</option>@foreach($levels as $k)<option value="{{ $k->notation }}" @selected(old('confidence_level', $c?->confidenceLevel?->notation) === $k->notation)>{{ $k->label($lang) }}</option>@endforeach</select>
<input id="{{ $x }}-conf" name="confidence" type="number" min="0" max="1" step="0.01" value="{{ old('confidence', $c?->confidence) }}" style="width: 5rem" aria-label="{{ __('Confidence (0 to 1)') }}"><br>
<details @if($c?->subject_label || $c?->predicate) open @endif><summary>{{ __('As an assertion') }}</summary>
    <label for="{{ $x }}-subj">{{ __('About (an entity\'s persistent IRI)') }}</label> <input id="{{ $x }}-subj" name="subject_iri" size="40">
    <label for="{{ $x }}-subjl">{{ __('or by name') }}</label> <input id="{{ $x }}-subjl" name="subject_label" size="25" value="{{ old('subject_label', $c?->subject_label) }}"><br>
    <label for="{{ $x }}-pred">{{ __('Predicate') }}</label> <input id="{{ $x }}-pred" name="predicate" size="25" value="{{ old('predicate', $c?->predicate) }}">
    <label for="{{ $x }}-obj">{{ __('Of (an entity\'s persistent IRI)') }}</label> <input id="{{ $x }}-obj" name="object_iri" size="40">
    <label for="{{ $x }}-objl">{{ __('or by name') }}</label> <input id="{{ $x }}-objl" name="object_label" size="25" value="{{ old('object_label', $c?->object_label) }}">
</details>
<details><summary>{{ __('The claim ledger') }}</summary>
    <label for="{{ $x }}-et">{{ __('Kind of evidence') }}</label> <select id="{{ $x }}-et" name="evidence_type"><option value="">{{ __('None') }}</option>@foreach($evidenceTypes as $k)<option value="{{ $k->notation }}" @selected(old('evidence_type', $c?->evidenceType?->notation) === $k->notation)>{{ $k->label($lang) }}</option>@endforeach</select>
    <label for="{{ $x }}-prov">{{ __('Where it comes from') }}</label> <select id="{{ $x }}-prov" name="provenance_kind">@foreach($provenances as $k)<option value="{{ $k->notation }}" @selected(old('provenance_kind', $c?->provenanceKind?->notation ?? 'original') === $k->notation)>{{ $k->label($lang) }}</option>@endforeach</select><br>
    @foreach(['supporting_sources' => 'Sources for it', 'opposing_sources' => 'Sources against it', 'quotations' => 'Quotations', 'method_theory_link' => 'Link to method and theory', 'researcher_notes' => 'Notes', 'unresolved_weaknesses' => 'Weaknesses not yet resolved', 'ethical_concerns' => 'Ethical concerns'] as $f => $l)
        <label for="{{ $x }}-{{ $f }}">{{ __($l) }}</label><br><textarea id="{{ $x }}-{{ $f }}" name="{{ $f }}" rows="2" cols="80">{{ old($f, $c?->{$f}) }}</textarea><br>
    @endforeach
</details>
