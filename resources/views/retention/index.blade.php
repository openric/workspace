{{-- OpenRiC Workspace - retention and disposal (roadmap phase 13b, ADR 0015), after Heratio's records management dashboard: schedules, records due, reviews due, disposals. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Retention and disposal'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Retention and disposal') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <h2>{{ __('Retention schedules') }}</h2>
    @if($schedules->isEmpty())<p>{{ __('None yet.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Reference') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Authority') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Classes') }}</th></tr></thead>
        <tbody>@foreach($schedules as $s)<tr><td><a href="{{ route('retention.schedule', $s) }}">{{ $s->reference }} v{{ $s->version }}</a></td><td>{{ $s->title }}</td><td>{{ $s->authority }}</td><td>{{ $s->status->label($lang) }}</td><td>{{ $s->classes_count }}</td></tr>@endforeach</tbody></table>@endif
    @if($manages)
        <details><summary>{{ __('Add a retention schedule') }}</summary>
            <form method="post" action="{{ route('retention.schedules.store') }}">@csrf @include('retention._schedule-fields', ['s' => null])<button type="submit">{{ __('Add') }}</button></form></details>
    @endif
    <h2>{{ __('Due for disposal') }}</h2>
    @if($due->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Class') }}</th><th scope="col">{{ __('Due') }}</th><th scope="col">{{ __('Then') }}</th></tr></thead>
        <tbody>@foreach($due as $a)<tr><td><a href="{{ route('records.show', $a->record) }}#retention">{{ \App\Relations\EntityKinds::label($a->record) }}</a></td><td>{{ $a->disposalClass->reference }}</td>
            <td>{{ ($a->override_due_on ?? $a->disposal_due_on)->format('Y-m-d') }}</td><td>{{ $a->disposalClass->review_required ? __('Review') : $a->disposalClass->action->label($lang) }}</td></tr>@endforeach</tbody></table>@endif
    <h2>{{ __('Reviews to do') }}</h2>
    @if($reviews->isEmpty())<p>{{ __('None.') }}</p>@else
    @foreach($reviews as $r)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0"><p><a href="{{ route('records.show', $r->record) }}#retention">{{ \App\Relations\EntityKinds::label($r->record) }}</a> · {{ __('due :date', ['date' => $r->due_on->format('Y-m-d')]) }}@if($r->assignedTo) · {{ $r->assignedTo->name }}@endif</p>
            <form method="post" action="{{ route('retention.reviews.decide', $r) }}">@csrf
                <label for="d-{{ $r->id }}">{{ __('Decision') }}</label> <select id="d-{{ $r->id }}" name="decision">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'review-decision/')->get() as $d)<option value="{{ $d->notation }}">{{ $d->label($lang) }}</option>@endforeach</select>
                <label for="n-{{ $r->id }}">{{ __('Next review (to keep for now)') }}</label> <input id="n-{{ $r->id }}" type="date" name="next_due_on">
                <label for="dn-{{ $r->id }}">{{ __('Notes') }}</label> <input id="dn-{{ $r->id }}" name="decision_notes" size="30"> <button type="submit">{{ __('Decide') }}</button></form></section>
    @endforeach @endif
    <h2>{{ __('Disposals') }}</h2>
    <p>@foreach($counts as $status => $n){{ \App\Models\Concept::where('iri', \App\Support\Vocab::BASE.'disposal-status/'.$status)->first()?->label($lang) }}: {{ $n }}@if(! $loop->last) · @endif @endforeach</p>
    @if($disposals->isNotEmpty())
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Initiated') }}</th></tr></thead>
        <tbody>@foreach($disposals as $d)<tr><td><a href="{{ route('disposals.show', $d) }}">{{ \App\Relations\EntityKinds::label($d->record) }}</a></td><td>{{ $d->action->label($lang) }}</td><td>{{ $d->status->label($lang) }}</td><td>{{ $d->initiated_at->format('Y-m-d') }}</td></tr>@endforeach</tbody></table>@endif
@endsection
