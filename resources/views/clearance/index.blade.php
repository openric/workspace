{{-- OpenRiC Workspace - security clearance: levels, compartments, accounts' clearances, requests and classified records (Heratio's security clearance dashboard; roadmap phase 18c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Security clearance'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    <h1>{{ __('Security clearance') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('clearance')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('clearance'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A classified record, its files and its search hits reach only an account cleared to its level, with every compartment it is in. The public never sees one. Your own clearance') }}: <strong>{{ $mine?->name ?? __('none') }}</strong>.</p>

    <h2>{{ __('Requests for access') }}</h2>
    @forelse($requests as $r)
        <p>{{ $r->user->name }} · <a href="{{ route('records.show', $r->record) }}">{{ $r->record->iri }}</a> · {{ $r->requestType->label($lang) }} · {{ $r->priority->label($lang) }} · {{ $r->justification }}
            <form method="post" action="{{ route('clearance.step', ['approve', $r->id]) }}" style="display: inline">@csrf<label for="h-{{ $loop->index }}">{{ __('for hours') }}</label> <input id="h-{{ $loop->index }}" name="duration_hours" type="number" min="1" style="width: 5rem"> <button type="submit">{{ __('Grant') }}</button></form>
            <form method="post" action="{{ route('clearance.step', ['deny', $r->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Deny') }}</button></form></p>
    @empty<p>{{ __('None waiting.') }}</p>@endforelse
    @if($grants->isNotEmpty())<p>{{ __('Access granted') }}:</p><ul>@foreach($grants as $g)<li>{{ $g->user->name }} · {{ $g->record->iri }} · {{ $g->requestType->label($lang) }}@if($g->expires_at) · {{ __('until :date', ['date' => $g->expires_at->timezone($tz)->format('j M Y H:i')]) }}@endif
        <form method="post" action="{{ route('clearance.step', ['revoke-grant', $g->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Revoke') }}</button></form></li>@endforeach</ul>@endif

    <h2>{{ __('Clearances') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Level') }}</th><th scope="col">{{ __('Compartments') }}</th><th scope="col">{{ __('Until') }}</th><th scope="col"></th></tr></thead>
        <tbody>@forelse($clearances as $c)<tr><td>{{ $c->user->name }}</td><td>{{ $c->level->name }}</td><td>{{ ($compartmentsHeld[$c->user_id] ?? collect())->pluck('compartment.code')->join(', ') ?: '-' }}</td>
            <td>@if($c->expires_at){{ $c->expires_at->timezone($tz)->format('Y-m-d') }}@if($c->expires_at->isPast()) <strong>({{ __('ended') }})</strong>@endif @else - @endif</td>
            <td><form method="post" action="{{ route('clearance.step', ['revoke', $c->user_id]) }}">@csrf<button type="submit">{{ __('Revoke') }}</button></form></td></tr>
        @empty<tr><td colspan="5">{{ __('No account is cleared.') }}</td></tr>@endforelse</tbody></table>
    <form method="post" action="{{ route('clearance.step', 'grant') }}">@csrf
        <label for="g-user">{{ __('Clear') }}</label> <select id="g-user" name="user_id">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
        <label for="g-level">{{ __('to') }}</label> <select id="g-level" name="level_id">@foreach($levels as $l)<option value="{{ $l->id }}">{{ $l->level }} {{ $l->name }}</option>@endforeach</select>
        <label for="g-until">{{ __('until') }}</label> <input id="g-until" name="expires_on" type="date">
        @if($compartments->isNotEmpty())<fieldset><legend>{{ __('Compartments') }}</legend>@foreach($compartments as $c)<label><input type="checkbox" name="compartments[]" value="{{ $c->id }}"> {{ $c->code }} {{ $c->name }}</label> @endforeach</fieldset>@endif
        <label for="g-notes">{{ __('Notes') }}</label> <input id="g-notes" name="notes" size="40"> <button type="submit">{{ __('Grant') }}</button></form>

    <h2>{{ __('Classified records') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Level') }}</th><th scope="col">{{ __('Review') }}</th><th scope="col">{{ __('Declassify') }}</th></tr></thead>
        <tbody>@forelse($classified as $c)<tr><td><a href="{{ route('records.show', $c->record) }}">{{ $c->record->iri }}</a></td><td>{{ $c->level->name }}@unless($c->inherit_to_children) <span class="meta">({{ __('this record only') }})</span>@endunless</td>
            <td>{{ $c->review_on?->format('Y-m-d') ?? '-' }}</td><td>{{ $c->declassify_on?->format('Y-m-d') ?? '-' }}@if($c->declassifyTo) → {{ $c->declassifyTo->name }}@endif</td></tr>
        @empty<tr><td colspan="4">{{ __('None.') }}</td></tr>@endforelse</tbody></table>

    <h2>{{ __('Levels') }}</h2>
    @foreach($levels as $l)
        <details><summary>{{ $l->level }} · {{ $l->code }} · {{ $l->name }}@unless($l->is_active) ({{ __('not in use') }})@endunless</summary>
            <form method="post" action="{{ route('clearance.step', ['level', $l->id]) }}">@csrf @include('clearance._level-fields', ['l' => $l, 'x' => 'l'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
    @endforeach
    <details><summary>{{ __('Add a level') }}</summary><form method="post" action="{{ route('clearance.step', 'level') }}">@csrf @include('clearance._level-fields', ['l' => null, 'x' => 'ln'])<button type="submit">{{ __('Add') }}</button></form></details>

    <h2>{{ __('Compartments') }}</h2>
    @foreach($compartments as $c)
        <details><summary>{{ $c->code }} · {{ $c->name }} · {{ __('from :level', ['level' => $c->minLevel->name]) }}</summary>
            <form method="post" action="{{ route('clearance.step', ['compartment', $c->id]) }}">@csrf @include('clearance._compartment-fields', ['c' => $c, 'x' => 'c'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
    @endforeach
    <details><summary>{{ __('Add a compartment') }}</summary><form method="post" action="{{ route('clearance.step', 'compartment') }}">@csrf @include('clearance._compartment-fields', ['c' => null, 'x' => 'cn'])<button type="submit">{{ __('Add') }}</button></form></details>

    @if($history->isNotEmpty())<h2>{{ __('History') }}</h2><ul class="meta">@foreach($history as $h)<li>{{ $h->created_at->timezone($tz)->format('j M Y H:i') }} · {{ $h->user->name }} · {{ $h->action->label($lang) }}@if($h->previousLevel) · {{ $h->previousLevel->name }}@endif @if($h->newLevel) → {{ $h->newLevel->name }}@endif @if($h->changedBy) · {{ $h->changedBy->name }}@endif @if($h->reason) · {{ $h->reason }}@endif</li>@endforeach</ul>@endif
@endsection
