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
@php($shown = $relations->filter(fn ($r) => $types === null ? ! (config('workspace.relation_types.'.$r->relationType->notation)['access_point'] ?? false) : in_array($r->relationType->notation, $types, true))
    ->filter(fn ($r) => $canUpdate || in_array($r->standing(), ['accepted', 'disputed'], true)))
@forelse($shown as $relation)
    @php($from = $relation->datedAs('beginning')?->normalized_value)
    @php($to = $relation->datedAs('end')?->normalized_value)
    @php($summary = ucfirst($relation->phrase()).' '.\App\Relations\EntityKinds::label($relation->other)
        .($relation->role ? ', as '.$relation->role->label(['en', 'und']) : '')
        .(($from || $to) ? ', '.($from ?? '?').' to '.($to ?? '?') : '')
        .' ('.$standingLabels[$relation->standing()].')')
    <details>
        <summary>{{ $summary }}</summary>
        <p><a href="{{ \App\Relations\EntityKinds::route($relation->other) }}">{{ \App\Relations\EntityKinds::label($relation->other) }}</a>.
           {{ collect([$relation->relationType->label(['en', 'und']), $relation->state?->label(['en', 'und']), $relation->certainty ? mb_strtolower($relation->certainty->label(['en', 'und'])) : null])->filter()->join(', ') }}.
           @if($relation->description)<br><span lang="{{ $relation->language }}">{{ $relation->description }}</span>@endif
           @if($relation->source_of_information)<br>Source: <span lang="{{ $relation->language }}">{{ $relation->source_of_information }}</span>@endif
           <br><strong>Persistent IRI:</strong> <code>{{ $relation->iri }}</code></p>

        <h3>Assertions</h3>
        @php($current = $relation->currentAssertions()->pluck('id')->all())
        <ul>
        @foreach($relation->assertions as $assertion)
            <li>{{ $assertion->status->label(['en', 'und']) }}: {{ $assertion->asserter() }} ({{ mb_strtolower($assertion->origin->label(['en', 'und'])) }}), {{ $assertion->asserted_at->format('Y-m-d H:i') }}
                @if($assertion->basis), on the basis: <span lang="{{ $assertion->basis_language }}">{{ $assertion->basis }}</span>@endif
                @if($assertion->reviewer); reviewed by {{ $assertion->reviewer->name }}, {{ $assertion->reviewed_at->format('Y-m-d') }}@endif
                @unless(in_array($assertion->id, $current, true)); superseded @endunless
                @if($canUpdate && $assertion->status->notation === 'proposed')
                    <form method="post" action="{{ route('relations.review', [$kind, $subject->getKey(), $relation, $assertion]) }}" style="display: inline">@csrf<input type="hidden" name="decision" value="accept"><button type="submit">Accept</button></form>
                    <form method="post" action="{{ route('relations.review', [$kind, $subject->getKey(), $relation, $assertion]) }}" style="display: inline">@csrf<input type="hidden" name="decision" value="reject"><button type="submit">Reject</button></form>
                @endif
            </li>
        @endforeach
        </ul>

        <h3>Evidence</h3>
        @forelse($relation->evidence as $evidence)
            <p><a href="{{ route('records.show', $evidence->record) }}">{{ $evidence->record->described('title', ['en']) }}</a>
                @if($canUpdate)<form method="post" action="{{ route('relations.evidence.destroy', [$kind, $subject->getKey(), $relation, $evidence]) }}" style="display: inline">@csrf<button type="submit">Remove evidence</button></form>@endif</p>
        @empty
            <p>No unit of description is linked as evidence.</p>
        @endforelse

        @if($canUpdate)
            <p><a href="{{ route('relations.evidence', [$kind, $subject->getKey(), $relation]) }}">Add evidence</a></p>
            <details>
                <summary>Assert or dispute this relation</summary>
                <form method="post" action="{{ route('relations.assert', [$kind, $subject->getKey(), $relation]) }}">
                    @csrf
                    <fieldset style="border: 0; padding: 0"><legend>Does the relation hold?</legend>
                        <label><input type="radio" name="holds" value="yes" checked> Yes, I assert it</label>
                        <label><input type="radio" name="holds" value="no"> No, I dispute it</label>
                    </fieldset>
                    <label for="{{ $section }}a{{ $loop->index }}-basis">On what basis (optional)</label>
                    <textarea id="{{ $section }}a{{ $loop->index }}-basis" name="basis" rows="2" style="width: 100%; font: inherit; box-sizing: border-box"></textarea>
                    <label for="{{ $section }}a{{ $loop->index }}-language">Language of the basis (BCP 47)</label>
                    <input id="{{ $section }}a{{ $loop->index }}-language" name="basis_language" maxlength="35" placeholder="en">
                    <label for="{{ $section }}a{{ $loop->index }}-supersedes">Supersedes (optional)</label>
                    <select id="{{ $section }}a{{ $loop->index }}-supersedes" name="supersedes" style="font: inherit; padding: .5rem">
                        <option value="">Nothing; it stands beside the others</option>
                        @foreach($relation->currentAssertions() as $assertion)<option value="{{ $assertion->id }}">{{ $assertion->asserter() }}, {{ $assertion->asserted_at->format('Y-m-d H:i') }}</option>@endforeach
                    </select>
                    <button type="submit">Record assertion</button>
                </form>
            </details>
            <details>
                <summary>Edit what is said about it</summary>
                <form method="post" action="{{ route('relations.update', [$kind, $subject->getKey(), $relation]) }}">
                    @csrf
                    @include('description._relation-fields', ['prefix' => $section.'r'.$loop->index, 'relation' => $relation,
                        'withRole' => (bool) (config('workspace.relation_types.'.$relation->relationType->notation)['role'] ?? false)])
                    <button type="submit">Save relation</button>
                </form>
            </details>
            <form method="post" action="{{ route('relations.destroy', [$kind, $subject->getKey(), $relation]) }}">
                @csrf
                <button type="submit">Remove relation (made in error)</button>
            </form>
        @endif
    </details>
@empty
    <p>None yet.</p>
@endforelse
@if($canUpdate)<p><a href="{{ route('relations.create', [$kind, $subject->getKey(), ...($add ? ['relate' => $add] : [])]) }}">{{ $addLabel }}</a></p>@endif
