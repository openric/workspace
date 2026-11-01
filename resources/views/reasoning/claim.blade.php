{{-- OpenRiC Workspace - a claim and its evidence (Heratio's claim ledger; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Claim'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('reasoning._nav')
    <h1>{{ __('Claim') }}</h1>
    <p style="white-space: pre-wrap; font-size: 1.1rem">{{ $claim->object_value }}</p>
    <p class="meta">{{ $claim->claimType->label($lang) }} · {{ $claim->status->label($lang) }}@if($claim->confidenceLevel) · {{ $claim->confidenceLevel->label($lang) }}@endif @if($claim->confidence !== null) ({{ number_format($claim->confidence, 2) }})@endif
        · {{ __('version :n', ['n' => $claim->version]) }} · {{ $claim->user?->name }}@if($claim->evidenceType) · {{ $claim->evidenceType->label($lang) }}@endif @if($claim->provenanceKind) · {{ $claim->provenanceKind->label($lang) }}@endif</p>
    @if($claim->subject_label || $claim->predicate)<p>{{ $claim->subject_label }} <em>{{ $claim->predicate }}</em> {{ $claim->object_label }}</p>@endif
    @foreach(['supporting_sources' => 'Sources for it', 'opposing_sources' => 'Sources against it', 'quotations' => 'Quotations', 'method_theory_link' => 'Link to method and theory', 'researcher_notes' => 'Notes', 'unresolved_weaknesses' => 'Weaknesses not yet resolved', 'ethical_concerns' => 'Ethical concerns'] as $f => $l)
        @if($claim->{$f})<p><strong>{{ __($l) }}:</strong> <span style="white-space: pre-wrap">{{ $claim->{$f} }}</span></p>@endif
    @endforeach
    <h2>{{ __('Evidence') }}</h2>
    @forelse($claim->evidence as $e)
        @php($d = $service->describe($e, auth()->user()))
        <p><strong>{{ $e->relationship->label($lang) }}</strong> · <span class="meta">{{ $e->sourceType->label($lang) }}</span> · @if($d['url'])<a href="{{ $d['url'] }}">{{ $d['label'] }}</a>@else{{ $d['label'] }}@endif @if($e->note) · {{ $e->note }}@endif <span class="meta">{{ $e->addedBy?->name }}</span>
            @if($level >= 3 || $level >= 2 && $e->added_by_id === auth()->id())<form method="post" action="{{ route('research.reasoning.evidence.remove', $e) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty<p class="error">{{ __('No evidence yet.') }}</p>@endforelse
    @if($level >= 2)
        @include('reasoning._evidence-form', ['action' => route('research.reasoning.claims.step', [$project, $claim, 'evidence']), 'x' => 'ce'])
        <h2>{{ __('Change') }}</h2>
        <form method="post" action="{{ route('research.reasoning.claims.update', [$project, $claim]) }}">@csrf @include('reasoning._claim-fields', ['c' => $claim, 'x' => 'ced'])<button type="submit">{{ __('Save') }}</button></form>
        @if($level >= 3 || $claim->user_id === auth()->id())<form method="post" action="{{ route('research.reasoning.claims.step', [$project, $claim, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the claim') }}</button></form>@endif
    @endif
@endsection
