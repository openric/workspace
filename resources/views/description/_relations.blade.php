{{-- OpenRiC Workspace - the relations of a described entity, with their assertions and evidence (ADR 0006, WS-006). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs: $subject, $kind (URL segment), $relations (RelationService::of), $canUpdate. Without update, only accepted and disputed relations show. --}}
{{-- Optional: $heading; $types, the relation types to show (default: all but access points, config workspace.relation_types); $section, a prefix that keeps
     element ids apart when the partial is included more than once; $add, the relate value (type|side) the add link starts from; $addLabel. --}}
@php($choices = \App\Http\Controllers\RelationController::choices())
@php($standingLabels = ['accepted' => 'accepted', 'disputed' => 'disputed', 'proposed' => 'proposed, awaiting review', 'rejected' => 'rejected'])
@php($heading ??= 'Relations')
@php($types ??= null)
@php($section ??= 'rel')
@php($add ??= null)
@php($addLabel ??= 'Add a relation')
<h2>{{ $heading }}</h2>
@php($shown = $relations->filter(fn ($r) => $types === null ? ! ((config('workspace.relation_types.'.$r->relationType->notation)['access_point'] ?? false) || (config('workspace.relation_types.'.$r->relationType->notation)['own_section'] ?? false)) : in_array($r->relationType->notation, $types, true))
    ->filter(fn ($r) => $canUpdate || in_array($r->standing(), ['accepted', 'disputed'], true)))
@forelse($shown as $relation)
    @php($from = $relation->datedAs('beginning')?->normalized_value)
    @php($to = $relation->datedAs('end')?->normalized_value)
    @php($summary = ucfirst($relation->phrase()).' '.\App\Relations\EntityKinds::label($relation->other)
        .($relation->role ? ', as '.$relation->role->label(\App\Support\Vocab::languages()) : '')
        .(($from || $to) ? ', '.($from ?? '?').' to '.($to ?? '?') : '')
        .' ('.$standingLabels[$relation->standing()].')')
    <details>
        <summary>{{ $summary }} @include('description._ric', ['term' => $relation->ricTerm()])@if($canUpdate) <small lang="en">{{ __('- assert, edit or remove') }}</small>@endif</summary>
        <p><a href="{{ \App\Relations\EntityKinds::route($relation->other) }}">{{ \App\Relations\EntityKinds::label($relation->other) }}</a>.
           {{ collect([$relation->relationType->label(\App\Support\Vocab::languages()), $relation->state?->label(\App\Support\Vocab::languages()), $relation->certainty ? mb_strtolower($relation->certainty->label(\App\Support\Vocab::languages())) : null])->filter()->join(', ') }}.
           @if($relation->description)<br><span lang="{{ $relation->language }}">{{ $relation->description }}</span>@endif
           @if($relation->source_of_information)<br>{{ __('Source') }}: <span lang="{{ $relation->language }}">{{ $relation->source_of_information }}</span>@endif
           <br><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $relation->iri }}</code></p>

        <h3>{{ __('Assertions') }}</h3>
        @php($current = $relation->currentAssertions()->pluck('id')->all())
        <ul>
        @foreach($relation->assertions as $assertion)
            <li>{{ $assertion->status->label(\App\Support\Vocab::languages()) }}: {{ $assertion->asserter() }} ({{ mb_strtolower($assertion->origin->label(\App\Support\Vocab::languages())) }}), {{ $assertion->asserted_at->format('Y-m-d H:i') }}
                @if($assertion->basis), {{ __('on the basis:') }} <span lang="{{ $assertion->basis_language }}">{{ $assertion->basis }}</span>@endif
                @if($assertion->reviewer); {{ __('reviewed by :name, :date', ['name' => $assertion->reviewer->name, 'date' => $assertion->reviewed_at->format('Y-m-d')]) }}@endif
                @unless(in_array($assertion->id, $current, true)); {{ __('superseded') }} @endunless
                @if($canUpdate && $assertion->status->notation === 'proposed')
                    <form method="post" action="{{ route('relations.review', [$kind, $subject->getKey(), $relation, $assertion]) }}" style="display: inline">@csrf<input type="hidden" name="decision" value="accept"><button type="submit">{{ __('Accept') }}</button></form>
                    <form method="post" action="{{ route('relations.review', [$kind, $subject->getKey(), $relation, $assertion]) }}" style="display: inline">@csrf<input type="hidden" name="decision" value="reject"><button type="submit">{{ __('Reject') }}</button></form>
                @endif
            </li>
        @endforeach
        </ul>

        <h3>{{ __('Evidence') }}</h3>
        @forelse($relation->evidence as $evidence)
            <p><a href="{{ route('records.show', $evidence->record) }}">{{ $evidence->record->described('title', ['en']) }}</a>
                @if($canUpdate)<form method="post" action="{{ route('relations.evidence.destroy', [$kind, $subject->getKey(), $relation, $evidence]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove evidence') }}</button></form>@endif</p>
        @empty
            <p>{{ __('No unit of description is linked as evidence.') }}</p>
        @endforelse

        @if($canUpdate)
            <p><a href="{{ route('relations.evidence', [$kind, $subject->getKey(), $relation]) }}">{{ __('Add evidence') }}</a></p>
            <details>
                <summary>{{ __('Assert or dispute this relation') }}</summary>
                <form method="post" action="{{ route('relations.assert', [$kind, $subject->getKey(), $relation]) }}">
                    @csrf
                    <fieldset style="border: 0; padding: 0"><legend>{{ __('Does the relation hold?') }}</legend>
                        <label><input type="radio" name="holds" value="yes" checked> {{ __('Yes, I assert it') }}</label>
                        <label><input type="radio" name="holds" value="no"> {{ __('No, I dispute it') }}</label>
                    </fieldset>
                    <label for="{{ $section }}a{{ $loop->index }}-basis">{{ __('On what basis (optional)') }}</label>
                    <textarea id="{{ $section }}a{{ $loop->index }}-basis" name="basis" rows="2" style="width: 100%; font: inherit; box-sizing: border-box"></textarea>
                    <label for="{{ $section }}a{{ $loop->index }}-language">{{ __('Language of the basis (BCP 47)') }}</label>
                    <input id="{{ $section }}a{{ $loop->index }}-language" name="basis_language" maxlength="35" placeholder="en">
                    <label for="{{ $section }}a{{ $loop->index }}-supersedes">{{ __('Supersedes (optional)') }}</label>
                    <select id="{{ $section }}a{{ $loop->index }}-supersedes" name="supersedes" style="font: inherit; padding: .5rem">
                        <option value="">{{ __('Nothing; it stands beside the others') }}</option>
                        @foreach($relation->currentAssertions() as $assertion)<option value="{{ $assertion->id }}">{{ $assertion->asserter() }}, {{ $assertion->asserted_at->format('Y-m-d H:i') }}</option>@endforeach
                    </select>
                    <button type="submit">{{ __('Record assertion') }}</button>
                </form>
            </details>
            <details>
                <summary>{{ __('Edit what is said about it') }}</summary>
                <form method="post" action="{{ route('relations.update', [$kind, $subject->getKey(), $relation]) }}">
                    @csrf
                    @include('description._relation-fields', ['prefix' => $section.'r'.$loop->index, 'relation' => $relation, 'type' => $relation->relationType->notation,
                        'withRole' => (bool) (config('workspace.relation_types.'.$relation->relationType->notation)['role'] ?? false)])
                    <button type="submit">{{ __('Save relation') }}</button>
                </form>
            </details>
            <form method="post" action="{{ route('relations.destroy', [$kind, $subject->getKey(), $relation]) }}">
                @csrf
                <button type="submit">{{ __('Remove relation (made in error)') }}</button>
            </form>
        @endif
    </details>
@empty
    <p>{{ __('None yet.') }}</p>
@endforelse
@if($canUpdate)<p><a href="{{ route('relations.create', [$kind, $subject->getKey(), ...($add ? ['relate' => $add] : [])]) }}">{{ $addLabel }}</a></p>@endif
