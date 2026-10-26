{{-- OpenRiC Workspace - the names of a described entity, as rico:Name entities (ADR 0006). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs: $subject (Describable), $kind (URL segment), $canUpdate. Shows drafts only with $drafts (default true). --}}
@php($drafts ??= true)
@php($nameTypes = \App\Models\Concept::choices(\App\Support\Vocab::NAME_TYPES)->get())
@php($names = $subject->names->filter(fn ($n) => $drafts || $n->state->iri === \App\Support\Vocab::valueState('published'))
    ->sortBy(fn ($n) => [$nameTypes->search(fn ($t) => $t->id === $n->name_type_id), ! $n->is_original, $n->language]))
<h2>Names @include('description._ric', ['term' => \App\Relations\EntityKinds::ricName($subject)])</h2>
@forelse($names as $name)
    @php($from = $name->usedDate('used-from')?->normalized_value)
    @php($to = $name->usedDate('used-to')?->normalized_value)
    @php($summary = $name->nameType->label(\App\Support\Vocab::languages()).': '.$name->value.' ('.$name->language.', '.$name->state->label(\App\Support\Vocab::languages()).(($from || $to) ? ', used '.($from ?? '?').' to '.($to ?? '?') : '').')')
    @unless($canUpdate)
        <p lang="{{ $name->language }}">{{ $summary }}</p>
        @continue
    @endunless
    <details>
        <summary><span lang="{{ $name->language }}">{{ $summary }}</span> <small lang="en">{{ __('- edit or remove') }}</small></summary>
        <form method="post" action="{{ route('names.update', [$kind, $subject->getKey(), $name]) }}">
            @csrf
            @include('description._name-fields', ['name' => $name, 'prefix' => 'n'.$loop->index, 'from' => $from, 'to' => $to])
            <button type="submit">{{ __('Save name') }}</button>
        </form>
        <form method="post" action="{{ route('names.destroy', [$kind, $subject->getKey(), $name]) }}">
            @csrf
            <button type="submit">{{ __('Remove name') }}</button>
        </form>
        <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $name->iri }}</code></p>
    </details>
@empty
    <p>{{ __('None yet.') }}</p>
@endforelse
@if($canUpdate)
<details>
    <summary>{{ __('Add name') }}</summary>
    <form method="post" action="{{ route('names.store', [$kind, $subject->getKey()]) }}">
        @csrf
        @include('description._name-fields', ['name' => null, 'prefix' => 'new-name', 'from' => null, 'to' => null])
        <button type="submit">{{ __('Add name') }}</button>
    </form>
</details>
@endif
