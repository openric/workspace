{{-- OpenRiC Workspace - the repatriation claims about a record (roadmap phase 17d2), with a way to register one. Needs: $record. Shown to who may manage repatriation claims. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($repatriationClaims = \App\Models\RepatriationClaim::where('record_resource_id', $record->id)->with('status.labels')->orderByDesc('updated_at')->get())
<section id="repatriation">
    <h2>{{ __('Repatriation claims') }} @include('description._ric', ['term' => null])</h2>
    @forelse($repatriationClaims as $rc)
        <p><a href="{{ route('repatriation.dialogue', $rc) }}">{{ $rc->claimant_community ?: __('Unnamed') }}</a> · {{ $rc->status->label(\App\Support\Vocab::languages()) }} · {{ $rc->updated_at->format('Y-m-d') }}</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <p><a href="{{ route('repatriation.create', ['record' => $record->id]) }}">{{ __('Register a repatriation claim') }}</a></p>
</section>
