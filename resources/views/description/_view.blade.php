{{-- OpenRiC Workspace - the described elements of a description, one collapsible section per area. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $subject and $standard (Elements::ISDIAH or Elements::ISAD_G); $drafts (default true) false shows published values only. An element with no value, and an area with none, is left out; the edit page shows every element. --}}
@php
    $drafts ??= true;
    $all = collect(\App\Description\Elements::byArea($standard));
    $areas = $all->map(fn ($elements) => collect($elements)
        ->map(fn ($element) => [$element, $subject->valuesOf($element)
            ->filter(fn ($v) => $drafts || $v->state->iri === \App\Support\Vocab::valueState('published'))
            ->sortBy(fn ($v) => [! $v->is_original, $v->language])])
        ->filter(fn ($pair) => $pair[1]->isNotEmpty()))
        ->filter(fn ($shown) => $shown->isNotEmpty());
@endphp
@forelse($areas as $area => $shown)
    <details class="area" open>
        <summary><h2>{{ $area }}</h2> <span class="meta">{{ $shown->count() }} of {{ count($all[$area]) }} described</span></summary>
        @foreach($shown as [$element, $values])
            <h3 style="margin-bottom: .2rem">{{ \App\Description\Elements::number($element) }} {{ $element->label(\App\Support\Vocab::languages()) }} @include('description._ric', ['term' => $element])</h3>
            @foreach($values as $value)
                <p lang="{{ $value->language }}" style="margin: .2rem 0">{!! nl2br(e($value->value)) !!}</p>
                <p style="margin: 0 0 .6rem"><small>{{ $value->language }}, {{ $value->is_original ? 'original' : 'translation' }}, {{ $value->state->label(\App\Support\Vocab::languages()) }}</small></p>
            @endforeach
        @endforeach
    </details>
@empty
    <p class="meta">{{ __('Nothing is described yet; the edit page has every element.') }}</p>
@endforelse
