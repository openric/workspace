{{-- OpenRiC Workspace - duplicate archival descriptions: the queue, the rules and a scan (Heratio's ahg-dedupe; roadmap phase 22b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Duplicate descriptions'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Duplicate descriptions') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Archival descriptions that may be the same, found by the rules below once a week or when asked. Compare each pair, then dismiss it, confirm it, or merge one into the other: the one kept takes what the other has, and the other\'s IRI says it was merged.') }}</p>
    <form method="post" action="{{ route('duplicates.scan') }}">@csrf<button type="submit">{{ __('Look for duplicates now') }}</button></form>
    <p>@foreach($statuses as $s)@if($s->notation === $status)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('duplicates.index', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif @unless($loop->last) · @endunless @endforeach</p>
    <table><thead><tr><th scope="col">{{ __('Score') }}</th><th scope="col">{{ __('Why') }}</th><th scope="col">{{ __('One') }}</th><th scope="col">{{ __('The other') }}</th><th scope="col"></th></tr></thead>
        <tbody>@forelse($pairs as $p)<tr><td>{{ number_format($p->similarity_score * 100) }}%</td><td>{{ $p->ruleType->label($lang) }}</td>
            <td><a href="{{ route('records.show', $p->recordA) }}">{{ \App\Relations\EntityKinds::label($p->recordA) }}</a></td><td><a href="{{ route('records.show', $p->recordB) }}">{{ \App\Relations\EntityKinds::label($p->recordB) }}</a></td>
            <td><a href="{{ route('duplicates.show', $p) }}">{{ __('Compare') }}</a>@if($p->reviewedBy) <span class="meta">{{ $p->reviewedBy->name }}@if($p->review_notes) · {{ $p->review_notes }}@endif</span>@endif</td></tr>
        @empty<tr><td colspan="5">{{ __('None.') }}</td></tr>@endforelse</tbody></table>

    <h2>{{ __('Rules') }}</h2>
    @foreach($rules as $r)
        <details><summary>{{ $r->name }} · {{ $r->ruleType->label($lang) }} · {{ number_format($r->threshold * 100) }}%@if($r->repository) · {{ \App\Relations\EntityKinds::label($r->repository) }}@endif @unless($r->is_enabled) · <strong>{{ __('off') }}</strong>@endunless</summary>
            <form method="post" action="{{ route('duplicates.rules.update', $r) }}">@csrf @include('duplicates._rule-fields', ['r' => $r, 'x' => 'r'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
    @endforeach
    <details><summary>{{ __('Add a rule') }}</summary><form method="post" action="{{ route('duplicates.rules.store') }}">@csrf @include('duplicates._rule-fields', ['r' => null, 'x' => 'rn'])<button type="submit">{{ __('Add') }}</button></form></details>
@endsection
