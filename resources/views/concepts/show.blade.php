{{-- OpenRiC Workspace - one concept of the institution's own vocabularies. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $concept->anyLabel([app()->getLocale(), 'en']))
@section('content')
    @php($vocabulary = $concept->vocabulary)
    <p><a href="{{ route('vocabularies.show', $vocabulary) }}">{{ $vocabulary->title }}</a>@foreach($concept->ancestors() as $above) › <a href="{{ route('concepts.show', $above) }}">{{ $above->anyLabel(['en']) }}</a>@endforeach</p>
    <h1>{{ $concept->anyLabel([app()->getLocale(), 'en']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if($concept->deprecated_at)
        <p role="note"><strong>{{ __('Deprecated') }}</strong> {{ __('on :date: kept, so what cites it stays readable, but no longer offered.', ['date' => $concept->deprecated_at->format('Y-m-d')]) }}
            @if($concept->replacedBy) {{ __('Replaced by') }} <a href="{{ route('concepts.show', $concept->replacedBy) }}">{{ $concept->replacedBy->anyLabel(['en']) }}</a>.@endif</p>
    @endif
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $concept->iri }}</code>
        <br><strong>{{ __('Kind') }}:</strong> {{ $vocabulary->kind->label(\App\Support\Vocab::languages()) }}@if($concept->notation !== null)<br><strong>{{ __('Code') }}:</strong> {{ $concept->notation }}@endif</p>
    @php($canUpdate = auth()->user()->may('update', 'vocabulary', $concept))
    @php($canDelete = auth()->user()->may('delete', 'vocabulary', $concept))
    @if($canUpdate)<p><a href="{{ route('concepts.edit', $concept) }}" style="display: inline-block; padding: .5rem 1rem; background: var(--accent); color: var(--bg); text-decoration: none">{{ __('Edit') }}</a>
        @if($canDelete && ! $concept->deprecated_at) <a href="#delete" style="display: inline-block; padding: .5rem 1rem; border: 1px solid var(--error); color: var(--error); text-decoration: none">{{ __('Delete') }}</a>@endif</p>@endif

    @foreach($concept->labels->sortBy('language') as $label)
        <section lang="{{ $label->language }}">
            <h2 style="font-size: 1.1rem">{{ $label->pref_label }} <small lang="en">({{ $label->language }})</small></h2>
            @php($alts = $concept->altLabels->where('language', $label->language))
            @if($alts->where('is_hidden', false)->isNotEmpty())<p><span lang="en">{{ __('Use for') }}:</span> {{ $alts->where('is_hidden', false)->pluck('label')->join('; ') }}</p>@endif
            @if($canUpdate && $alts->where('is_hidden', true)->isNotEmpty())<p><span lang="en">{{ __('Hidden labels') }}:</span> {{ $alts->where('is_hidden', true)->pluck('label')->join('; ') }}</p>@endif
            @foreach(['definition' => 'Definition', 'scope_note' => 'Scope note', 'history_note' => 'History note', 'source_note' => 'Source note', 'display_note' => 'Display note'] as $note => $noteLabel)
                @if($label->{$note})<p><strong lang="en">{{ $noteLabel }}:</strong> {!! nl2br(e($label->{$note})) !!}</p>@endif
            @endforeach
        </section>
    @endforeach

    <h2>{{ __('Narrower concepts') }}</h2>
    @php($narrower = $concept->narrower->whereNull('deprecated_at')->sortBy(fn ($c) => mb_strtolower($c->anyLabel(['en']))))
    @forelse($narrower as $child)
        <p><a href="{{ route('concepts.show', $child) }}">{{ $child->anyLabel([app()->getLocale(), 'en']) }}</a></p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    @if(auth()->user()->may('create', 'vocabulary') && ! $concept->deprecated_at)<p><a href="{{ route('vocabularies.concepts.create', [$vocabulary, 'broader' => $concept->id]) }}">{{ __('Add a narrower concept') }}</a></p>@endif

    <h2>{{ __('Related concepts') }}</h2>
    @forelse($related as $other)
        <p><a href="{{ route('concepts.show', $other) }}">{{ $other->anyLabel([app()->getLocale(), 'en']) }}</a>@if($other->vocabulary_id !== $concept->vocabulary_id), in {{ $other->vocabulary->title }}@endif
            @if($canUpdate)<form method="post" action="{{ route('concepts.related.destroy', [$concept, $other]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    @if($canUpdate && $siblings->isNotEmpty())
        <form method="post" action="{{ route('concepts.related.store', $concept) }}" style="display: flex; gap: .5rem; align-items: end">
            @csrf
            <div><label for="related" style="margin-top: 0">{{ __('Relate to (skos:related)') }}</label>
                <select id="related" name="related" style="font: inherit; padding: .5rem">
                    @foreach($siblings as $sibling)<option value="{{ $sibling->id }}">{{ $sibling->anyLabel(['en']) }}</option>@endforeach
                </select></div>
            <button type="submit" style="margin-top: 0">{{ __('Relate') }}</button>
        </form>
    @endif

    <h2>{{ __('Matches in other vocabularies') }}</h2>
    @forelse($concept->matches as $match)
        <p>{{ $match->matchType->label(\App\Support\Vocab::languages()) }}: <a href="{{ $match->target_iri }}" rel="noopener noreferrer">{{ $match->target_label ?: $match->target_iri }}</a>@if($match->target_scheme) ({{ $match->target_scheme }})@endif
            @if($canUpdate)<form method="post" action="{{ route('concepts.matches.destroy', [$concept, $match]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    @if($canUpdate)
        <details>
            <summary>{{ __('Add a match') }}</summary>
            <form method="post" action="{{ route('concepts.matches.store', $concept) }}">
                @csrf
                <label for="m-type">{{ __('How it matches') }}</label>
                <select id="m-type" name="type" style="font: inherit; padding: .5rem">
                    @foreach($matchTypes as $type)<option value="{{ $type->notation }}">{{ $type->label(\App\Support\Vocab::languages()) }}</option>@endforeach
                </select>
                <label for="m-iri">{{ __('IRI of the concept elsewhere (for example in LCSH, AAT or Wikidata)') }}</label>
                <input id="m-iri" name="iri" type="url" required maxlength="2048" placeholder="https://id.loc.gov/authorities/subjects/sh85007034">
                <label for="m-label">{{ __('Its label (optional)') }}</label>
                <input id="m-label" name="target_label" maxlength="1024">
                <label for="m-scheme">{{ __('Its vocabulary (optional, such as LCSH)') }}</label>
                <input id="m-scheme" name="target_scheme" maxlength="255">
                <button type="submit">{{ __('Add match') }}</button>
            </form>
        </details>
    @endif

    <h2>{{ __('Community protocol') }}</h2>
    @php($lang = \App\Support\Vocab::languages())
    @if($protocol)
        <p><strong>{{ __('Access condition') }}:</strong> {{ $protocol->accessCondition->label($lang) }}@if($protocol->accessCondition->broader?->notation === 'withheld') ({{ __('withheld from the public, with every unit of description tagged with it') }})@endif
            @if($protocol->label)<br><strong>{{ __('Label') }}:</strong> {{ $protocol->label->label($lang) }}@endif
            @if($protocol->community)<br><strong>{{ __('Owning community') }}:</strong> <a href="{{ route('agents.show', $protocol->community) }}">{{ $protocol->community->described('agent-authorised-name', [app()->getLocale(), 'en']) }}</a>@endif
            @if($protocol->region)<br><strong>{{ __('Region') }}:</strong> {{ $protocol->region }}@endif</p>
    @else
        <p>{{ __('None: the term is open.') }}</p>
    @endif
    @if($canUpdate)
        <details>
            <summary>{{ $protocol ? __('Change the community protocol') : __('Set a community protocol') }}</summary>
            <form method="post" action="{{ route('concepts.protocol', $concept) }}">
                @csrf
                <small>{{ __('Traditional Knowledge or Biocultural Label and access condition. Withholding conditions hide the term, and every unit of description tagged with it, from the public and from accounts that may not change them.') }}</small>
                <label for="p-condition">{{ __('Access condition') }}</label>
                <select id="p-condition" name="access_condition" style="font: inherit; padding: .5rem">
                    @foreach($conditions as $c)<option value="{{ $c->notation }}" @selected(($protocol?->accessCondition->notation ?? 'open') === $c->notation)>{{ $c->label($lang) }}@if($c->broader->notation === 'withheld') ({{ __('hidden') }})@endif</option>@endforeach
                </select>
                <label for="p-label">{{ __('Label') }}</label>
                <select id="p-label" name="label" style="font: inherit; padding: .5rem">
                    <option value="">{{ __('None') }}</option>
                    @foreach($labelChoices as $group => $labels)<optgroup label="{{ strtoupper($group) }}">@foreach($labels as $l)<option value="{{ $l->notation }}" @selected($protocol?->label?->notation === $l->notation)>{{ $l->label($lang) }}</option>@endforeach</optgroup>@endforeach
                </select>
                <label for="p-community">{{ __('Owning community') }}</label>
                <select id="p-community" name="community" style="font: inherit; padding: .5rem">
                    <option value="">{{ __('None') }}</option>
                    @foreach($communities as $a)<option value="{{ $a->id }}" @selected($protocol?->community_id === $a->id)>{{ $a->described('agent-authorised-name', [app()->getLocale(), 'en']) }}</option>@endforeach
                </select>
                <label for="p-region">{{ __('Region') }}</label>
                <input id="p-region" name="region" maxlength="255" placeholder="southern_africa" value="{{ $protocol?->region }}">
                <button type="submit">{{ __('Save') }}</button>
            </form>
        </details>
    @endif

    @if($vocabulary->kind->notation === 'relation-type')
        <h2>{{ __('Relation type') }}</h2>
        @if($relationType)
            <p><strong>{{ __('Narrows') }}:</strong> {{ \App\Relations\RelationTypes::phrase($narrowable[$relationType['kind']] ?? $relationType, true) }} ({{ $relationType['kind'] }})
                @if($relationType['symmetric'])<br><strong>{{ __('Self-reciprocal') }}:</strong> {{ __('yes, as the type it narrows') }}@endif</p>
            <table>
                <thead><tr><th>{{ __('Language') }}</th><th>{{ __('How it reads') }}</th><th>{{ __('Converse term') }}</th></tr></thead>
                <tbody>@foreach($relationType['phrases'] as $language => $phrase)<tr><td>{{ $language }}</td><td>{{ $phrase['forward'] }}</td><td>{{ $phrase['inverse'] }}</td></tr>@endforeach</tbody>
            </table>
        @else
            <p>{{ __('Not a relation type yet: choose the bundled type it narrows and say how it reads.') }}</p>
        @endif
        @if($canUpdate)
            <details @if(! $relationType) open @endif>
                <summary>{{ $relationType ? __('Change the relation type') : __('Make it a relation type') }}</summary>
                <form method="post" action="{{ route('concepts.relation-type', $concept) }}">
                    @csrf
                    <small>{{ __('It connects what the bundled type connects, takes its agent role, and is exported as that type. A symmetric type reads the same both ways.') }}</small>
                    <label for="rt-base">{{ __('Narrows') }}</label>
                    <select id="rt-base" name="base" style="font: inherit; padding: .5rem">
                        @foreach($narrowable as $key => $type)<option value="{{ $key }}" @selected(($relationType['kind'] ?? null) === $key)>{{ \App\Relations\RelationTypes::phrase($type, true) }} / {{ \App\Relations\RelationTypes::phrase($type, false) }}</option>@endforeach
                    </select>
                    <label for="rt-language">{{ __('Language') }}</label>
                    <input id="rt-language" name="language" maxlength="35" required value="{{ app()->getLocale() }}">
                    <label for="rt-forward">{{ __('How it reads') }}</label>
                    <input id="rt-forward" name="forward" maxlength="255" required placeholder="{{ __('is the godparent of') }}" value="{{ $relationType['phrases'][app()->getLocale()]['forward'] ?? '' }}">
                    <label for="rt-inverse">{{ __('Converse term') }}</label>
                    <input id="rt-inverse" name="inverse" maxlength="255" placeholder="{{ __('is the godchild of') }}" value="{{ $relationType['phrases'][app()->getLocale()]['inverse'] ?? '' }}">
                    <button type="submit">{{ __('Save') }}</button>
                </form>
            </details>
        @endif
    @endif

    @include('description._relations', ['subject' => $concept, 'kind' => 'concepts', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Units of description about it', 'types' => ['subject'], 'section' => 'su', 'add' => $concept->deprecated_at ? null : 'subject|target',
        'addLabel' => 'Add a unit of description about it'])
    @if($vocabulary->kind->notation === 'place-type')
        <h2>{{ __('Places of this type') }}</h2>
        @forelse($places as $place)
            <p><a href="{{ route('places.show', $place) }}">{{ $place->described('place-name', [app()->getLocale(), 'en']) }}</a></p>
        @empty
            <p>{{ __('None.') }}</p>
        @endforelse
    @endif

    @if($canUpdate && $otherVocabularies->isNotEmpty() && ! $concept->deprecated_at)
        <h2>{{ __('Move') }}</h2>
        <form method="post" action="{{ route('concepts.move', $concept) }}" style="display: flex; gap: .5rem; align-items: end">
            @csrf
            <div><label for="vocabulary" style="margin-top: 0">{{ __('Move it, and every concept below it, to') }}</label>
                <select id="vocabulary" name="vocabulary" style="font: inherit; padding: .5rem">
                    @foreach($otherVocabularies as $other)<option value="{{ $other->id }}">{{ $other->title }}</option>@endforeach
                </select></div>
            <button type="submit" style="margin-top: 0">{{ __('Move') }}</button>
        </form>
    @endif

    @if($canDelete)
        <h2 id="delete">{{ __('Delete') }}</h2>
        <p>{{ __('A concept is never removed, so its IRI always resolves and what cites it stays readable: deleting deprecates it, and merging deprecates a duplicate in favour of another concept.') }}</p>
        @if($concept->deprecated_at)
            <form method="post" action="{{ route('concepts.restore', $concept) }}">@csrf<button type="submit">{{ __('Restore: offer it again') }}</button></form>
        @else
            @if($siblings->isNotEmpty())
            <form method="post" action="{{ route('concepts.merge', $concept) }}">
                @csrf
                <p>{{ __('For a duplicate: its narrower concepts, the units of description and places that use it, its related concepts and matches move to the concept kept; its labels become alternative labels there; it is deprecated and points to the one kept.') }}</p>
                <label for="into">{{ __('Merge into') }}</label>
                <select id="into" name="into" style="font: inherit; padding: .5rem">
                    @foreach($siblings as $sibling)<option value="{{ $sibling->id }}">{{ $sibling->anyLabel(['en']) }}</option>@endforeach
                </select>
                <button type="submit">{{ __('Merge') }}</button>
            </form>
            @endif
            <form method="post" action="{{ route('concepts.deprecate', $concept) }}">
                @csrf
                <button type="submit">{{ __('Delete (deprecate)') }}</button>
            </form>
        @endif
    @endif
@endsection
