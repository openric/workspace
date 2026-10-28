{{-- OpenRiC Workspace - the fields of a description, by area. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject, $standard and $language. --}}
@foreach(\App\Description\Elements::byArea($standard) as $area => $elements)
    <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
        <legend><strong>{{ $area }}</strong></legend>
        @foreach($elements as $element)
            @php($nameType = \App\Description\Elements::nameType($element->notation))
            @php($current = $subject->valuesOf($element)->where('language', $language)->pluck('value')->join("\n"))
            @php($single = $nameType === 'authorised' || in_array($element->notation, ['dates-expressed', 'extent'], true))
            <label for="v-{{ $element->notation }}">{{ \App\Description\Elements::number($element) }} {{ $element->label(\App\Support\Vocab::languages()) }}</label> @include('description._ric', ['term' => $element])
            <small id="h-{{ $element->notation }}">{{ $element->labels->firstWhere('language', 'en')?->definition }}</small>
            @if($codeKind = \App\Description\Elements::codeKind($element->notation))
                {{-- ISO codes, in no language: the same in every language of the form. --}}
                @php($chosen = (array) old('values.'.$element->notation, preg_split('/\s+/', (string) $subject->valuesOf($element)->firstWhere('language', 'zxx')?->value, -1, PREG_SPLIT_NO_EMPTY)))
                <input type="hidden" name="values[{{ $element->notation }}][]" value="">
                <select id="v-{{ $element->notation }}" name="values[{{ $element->notation }}][]" multiple size="6" aria-describedby="h-{{ $element->notation }}" style="width: 100%">
                    @foreach(\App\Description\IsoCodes::choices($codeKind) as $code => $codeName)<option value="{{ $code }}" @selected(in_array($code, $chosen, true))>{{ $codeName }} ({{ $code }})</option>@endforeach
                </select>
            @else
            <textarea id="v-{{ $element->notation }}" name="values[{{ $element->notation }}]" aria-describedby="h-{{ $element->notation }}" rows="{{ $single ? 1 : ($nameType ? 3 : 4) }}" style="width: 100%; font: inherit; box-sizing: border-box">{{ old('values.'.$element->notation, $current) }}</textarea>
            @endif
        @endforeach
    </fieldset>
@endforeach
