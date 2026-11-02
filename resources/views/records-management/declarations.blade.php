{{-- OpenRiC Workspace - declarations awaiting approval, declared records and vital records (Heratio's declaration and vital record pages; roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Declarations and vital records'))
@section('content')
    <h1>{{ __('Declarations and vital records') }}</h1>
    @include('records-management._nav')
    <h2>{{ __('Awaiting approval') }}</h2>
    @forelse($pending as $d)
        <p><a href="{{ route('records.show', $d->record) }}">{{ \App\Relations\EntityKinds::label($d->record) }}</a> <span class="meta">{{ __('asked by :name on :date', ['name' => $d->requestedBy?->name ?? '-', 'date' => $d->requested_at->format('j M Y')]) }}</span>@if($d->notes) · {{ $d->notes }}@endif
            <form method="post" action="{{ route('records-management.declarations.step', [$d, 'approve']) }}" style="display: inline">@csrf<button type="submit">{{ __('Declare') }}</button></form>
            <form method="post" action="{{ route('records-management.declarations.step', [$d, 'reject']) }}" style="display: inline">@csrf<button type="submit">{{ __('Reject') }}</button></form></p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    <h2>{{ __('Declared records') }}</h2>
    <p class="meta">{{ __('A declared record, and what carries it, can be removed only by its disposal, once cleared.') }}</p>
    <ul>@forelse($declared as $d)<li><a href="{{ route('records.show', $d->record) }}">{{ \App\Relations\EntityKinds::label($d->record) }}</a> <span class="meta">{{ $d->declared_at?->format('j M Y') }} · {{ $d->declaredBy?->name }}</span></li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    <h2>{{ __('Vital records') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Why') }}</th><th scope="col">{{ __('Next review') }}</th><th scope="col">{{ __('Last reviewed') }}</th><th scope="col"></th></tr></thead>
        <tbody>@forelse($vital as $v)<tr><td><a href="{{ route('records.show', $v->record) }}">{{ \App\Relations\EntityKinds::label($v->record) }}</a></td><td>{{ $v->reason }}</td>
            <td>@if($v->next_review_on->isPast())<strong>{{ $v->next_review_on->format('Y-m-d') }} ({{ __('overdue') }})</strong>@else{{ $v->next_review_on->format('Y-m-d') }}@endif · {{ trans_choice('every :count day|every :count days', $v->review_cycle_days, ['count' => $v->review_cycle_days]) }}</td>
            <td>{{ $v->last_reviewed_at?->format('Y-m-d') ?? '-' }}@if($v->lastReviewedBy) · {{ $v->lastReviewedBy->name }}@endif</td>
            <td><form method="post" action="{{ route('records-management.vital.step', [$v, 'review']) }}" style="display: inline">@csrf<button type="submit">{{ __('Reviewed') }}</button></form>
                <form method="post" action="{{ route('records-management.vital.step', [$v, 'unflag']) }}" style="display: inline">@csrf<button type="submit">{{ __('No longer vital') }}</button></form></td></tr>
        @empty<tr><td colspan="5">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
@endsection
