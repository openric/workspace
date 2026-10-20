{{-- OpenRiC Workspace - the fields of a description, by area. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject, $standard and $language. --}}
@foreach(\App\Description\Elements::byArea($standard) as $area => $elements)
    <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
        <legend><strong>{{ $area }}</strong></legend>
        @foreach($elements as $element)
            @php($current = $subject->descriptionValues->first(fn ($v) => $v->element_id === $element->id && $v->language === $language))
            @php($single = in_array($element->notation, ['authorised-name', 'other-name', 'title', 'dates-expressed', 'extent'], true))
            <label for="v-{{ $element->notation }}">{{ \App\Description\Elements::number($element) }} {{ $element->label(['en', 'und']) }}</label>
            <small id="h-{{ $element->notation }}">{{ $element->labels->firstWhere('language', 'en')?->definition }}</small>
            <textarea id="v-{{ $element->notation }}" name="values[{{ $element->notation }}]" aria-describedby="h-{{ $element->notation }}" rows="{{ $single ? 1 : 4 }}" style="width: 100%; font: inherit; box-sizing: border-box">{{ old('values.'.$element->notation, $current?->value) }}</textarea>
        @endforeach
    </fieldset>
@endforeach
