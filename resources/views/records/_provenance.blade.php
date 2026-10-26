{{-- OpenRiC Workspace - custody and provenance of a unit, in time order: who created, accumulated, held, owned and managed it. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $record and $relations. The current holder is the unit's repository; the rest are relations with their dates. --}}
@php($custody = $relations->filter(fn ($r) => in_array($r->relationType->notation, ['creation', 'accumulation', 'holding', 'ownership', 'management'], true) && in_array($r->standing(), ['accepted', 'disputed'], true))
    ->sortBy(fn ($r) => [$r->datedAs('beginning')?->earliest?->format('Y-m-d') ?? '9999', $r->created_at]))
<section>
    <h2>Custody and provenance</h2>
    @if($custody->isEmpty())
        <p>Only the current holder is recorded: {{ $record->repository->described('authorised-name', ['en']) }}. Former holders, owners and managers are relations with their dates.</p>
    @else
        <ol>
            @foreach($custody as $relation)
                @php($from = $relation->datedAs('beginning')?->normalized_value)
                @php($to = $relation->datedAs('end')?->normalized_value)
                <li>{{ ($from || $to) ? ($from ?? '?').' to '.($to ?? '?').': ' : 'Undated: ' }}{{ ucfirst($relation->phrase()) }} {{ \App\Relations\EntityKinds::label($relation->other) }}@if($relation->state), {{ mb_strtolower($relation->state->label(\App\Support\Vocab::languages())) }}@endif</li>
            @endforeach
            <li>Now held by {{ $record->repository->described('authorised-name', ['en']) }}.</li>
        </ol>
    @endif
</section>
