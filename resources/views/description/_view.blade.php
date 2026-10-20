{{-- OpenRiC Workspace - every element of a description, by area. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject and $standard (Elements::ISDIAH or Elements::ISAD_G). Empty elements are shown, so it is clear what is left to describe. --}}
@foreach(\App\Description\Elements::byArea($standard) as $area => $elements)
    <section>
        <h2>{{ $area }}</h2>
        @foreach($elements as $element)
            @php($values = $subject->descriptionValues->where('element_id', $element->id)->sortBy(fn ($v) => [! $v->is_original, $v->language]))
            <h3 style="margin-bottom: .2rem">{{ \App\Description\Elements::number($element) }} {{ $element->label(['en', 'und']) }}</h3>
            @forelse($values as $value)
                <p lang="{{ $value->language }}" style="margin: .2rem 0">{!! nl2br(e($value->value)) !!}</p>
                <p style="margin: 0 0 .6rem"><small>{{ $value->language }}, {{ $value->is_original ? 'original' : 'translation' }}, {{ $value->state->label(['en', 'und']) }}</small></p>
            @empty
                <p style="margin: .2rem 0"><small>Not yet described.</small></p>
            @endforelse
        @endforeach
    </section>
@endforeach
