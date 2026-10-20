{{-- OpenRiC Workspace - the creators of a unit (ISAD(G) 3.2.1) and the history their authority records give (3.2.2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $relations (RelationService::of the unit) and $drafts. --}}
@php($creators = $relations->filter(fn ($r) => in_array($r->relationType->notation, ['creation', 'accumulation'], true) && in_array($r->standing(), ['accepted', 'disputed'], true)))
<section>
    <h2>Creators (3.2.1)</h2>
    @forelse($creators as $relation)
        @php($agent = $relation->other)
        <p><a href="{{ \App\Relations\EntityKinds::route($agent) }}">{{ \App\Relations\EntityKinds::label($agent) }}</a>, {{ $relation->phrase() }}@if($relation->role) as {{ mb_strtolower($relation->role->label(['en', 'und'])) }}@endif</p>
        @if($agent instanceof \App\Models\Agent)
            @php($history = $agent->descriptionValues->first(fn ($v) => $v->element->notation === 'agent-history' && ($drafts || $v->state->iri === \App\Support\Vocab::valueState('published'))))
            @if($history)<blockquote lang="{{ $history->language }}" style="margin: .2rem 0 .8rem 1rem">{!! nl2br(e($history->value)) !!}<br><small lang="en">From the authority record (ISAAR(CPF) 5.2.2).</small></blockquote>@endif
        @endif
    @empty
        <p>No creator recorded. Add a creation relation under Relations.</p>
    @endforelse
</section>
