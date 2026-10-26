{{-- OpenRiC Workspace - a finding aid: a unit's description and every unit inside it, ready to print; or one unit alone (print). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', ($withInside ? 'Finding aid: ' : '').$record->described('title', ['en']))
@section('content')
    <p class="no-print"><a href="{{ route('records.show', $record) }}">{{ __('Back to :name', ['name' => $record->described('title', ['en'])]) }}</a>
        @if(auth()->user()->may('view-draft', 'description', $record))
            · @if($drafts)<a href="{{ url()->current() }}">{{ __('Published values only') }}</a>@else<a href="{{ url()->current() }}?drafts=1">{{ __('Include draft records') }}</a>@endif
        @endif</p>
    <p class="no-print"><small>{{ $drafts ? __('Drafts are included.') : __('Published values only.') }} {{ __('Use your browser\'s print command; the menu is left out of the printout.') }}</small></p>
    @php($published = \App\Support\Vocab::valueState('published'))
    @foreach($units as [$unit, $depth])
        <section style="margin-left: {{ min($depth, 6) }}rem; {{ $depth ? 'border-top: 1px solid var(--line); margin-top: 1rem' : '' }}">
            @php($title = $unit->namesOfType('authorised')->filter(fn ($n) => $drafts || $n->state->iri === $published)->first()?->value)
            <h{{ $depth === 0 ? 1 : 2 }} style="{{ $depth ? 'font-size: 1.1rem' : '' }}">{{ $title ?? '[title not published]' }}</h{{ $depth === 0 ? 1 : 2 }}>
            <p><small>{{ collect([$unit->level->label(\App\Support\Vocab::languages()), $unit->referenceCode()])->filter()->join(', ') }}</small></p>
            @foreach(\App\Description\Elements::of(\App\Description\Elements::ISAD_G) as $element)
                @continue(\App\Description\Elements::nameType($element->notation) === 'authorised')
                @php($values = $unit->valuesOf($element)->filter(fn ($v) => $drafts || $v->state->iri === $published)->sortBy(fn ($v) => [! $v->is_original, $v->language]))
                @if($values->isNotEmpty())
                    <p style="margin: .3rem 0"><strong>{{ $element->label(\App\Support\Vocab::languages()) }}:</strong>
                        @foreach($values as $value)<span lang="{{ $value->language }}">{!! nl2br(e($value->value)) !!}</span>@if(! $loop->last) / @endif @endforeach</p>
                @endif
            @endforeach
            @foreach($unit->dates as $date)<p style="margin: .3rem 0"><strong>{{ $date->property->label(\App\Support\Vocab::languages()) }}:</strong> {{ $date->display() }}</p>@endforeach
        </section>
    @endforeach
@endsection
