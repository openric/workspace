{{-- OpenRiC Workspace - the National Archives of Zimbabwe: closure periods, protected records, research permits, records schedules and transfers (Heratio's ahg-naz pages; roadmap phase 18e). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('National Archives of Zimbabwe'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($options = fn ($concepts, $selected = null) => $concepts->map(fn ($c) => '<option value="'.e($c->notation).'"'.($c->notation === $selected ? ' selected' : '').'>'.e($c->label($lang)).'</option>')->join(''))
    <h1>{{ __('National Archives of Zimbabwe') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif

    <h2>{{ __('Closure periods and protected records') }}</h2>
    <p class="meta">{{ __('A closure or protection is kept as an embargo on the record, so it holds wherever the record is read.') }}</p>
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Restriction') }}</th><th scope="col">{{ __('Until') }}</th><th scope="col"></th></tr></thead>
        <tbody>@forelse($restrictions as $r)<tr><td><a href="{{ route('records.show', $r->record) }}">{{ \App\Relations\EntityKinds::label($r->record) }}</a></td>
            <td>{{ ($r->closureType ?? $r->protectionType)->label($lang) }}@if($r->years) · {{ trans_choice(':count year|:count years', $r->years, ['count' => $r->years]) }}@endif @if($r->authority_reference) · {{ $r->authority_reference }}@endif</td>
            <td>{{ $r->embargo->ends_on?->format('Y-m-d') ?? __('indefinite') }}@if($r->embargo->lifted_at) · <strong>{{ __('released') }}</strong>@endif</td>
            <td>@unless($r->embargo->lifted_at)<form method="post" action="{{ route('jurisdiction.naz.step', ['release', $r->id]) }}">@csrf<label for="rr-{{ $loop->index }}">{{ __('Why') }}</label> <input id="rr-{{ $loop->index }}" name="reason" size="16"> <button type="submit">{{ __('Release') }}</button></form>@endunless</td></tr>
        @empty<tr><td colspan="4">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
    @foreach(['closure' => [__('Close a record'), $closureTypes], 'protection' => [__('Protect a record'), $protectionTypes]] as $kind => [$heading, $types])
        <details><summary>{{ $heading }}</summary><form method="post" action="{{ route('jurisdiction.naz.step', 'restrict') }}">@csrf<input type="hidden" name="kind" value="{{ $kind }}">
            <p><label for="{{ $kind }}-iri">{{ __('Record, by persistent IRI') }}</label> <input id="{{ $kind }}-iri" name="iri" size="60" required>
                <label for="{{ $kind }}-type">{{ __('Type') }}</label> <select id="{{ $kind }}-type" name="type">{!! $options($types) !!}</select></p>
            <p>@if($kind === 'closure')<label for="{{ $kind }}-years">{{ __('Years') }}</label> <input id="{{ $kind }}-years" name="years" type="number" min="1" max="200" value="25">@endif
                <label for="{{ $kind }}-start">{{ __('From') }}</label> <input id="{{ $kind }}-start" name="start_date" type="date"> <label for="{{ $kind }}-end">{{ __('to') }}</label> <input id="{{ $kind }}-end" name="end_date" type="date">
                <label for="{{ $kind }}-review">{{ __('Review') }}</label> <input id="{{ $kind }}-review" name="review_date" type="date"></p>
            <p><label for="{{ $kind }}-auth">{{ __('Authority reference') }}</label> <input id="{{ $kind }}-auth" name="authority_reference" size="30">
                <label><input type="checkbox" name="inside" value="1"> {{ __('and everything inside it') }}</label></p>
            <p><label for="{{ $kind }}-reason">{{ __('Reason') }}</label><br><textarea id="{{ $kind }}-reason" name="reason" rows="2" cols="70"></textarea></p>
            <button type="submit">{{ $heading }}</button></form></details>
    @endforeach

    <h2>{{ __('Research permits') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Permit') }}</th><th scope="col">{{ __('Researcher') }}</th><th scope="col">{{ __('Topic') }}</th><th scope="col">{{ __('Period') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"></th></tr></thead>
        <tbody>@forelse($permits as $p)<tr><td>{{ $p->permit_number }} <span class="meta">{{ $p->permitType->label($lang) }}</span></td><td>{{ $p->researcher->first_name }} {{ $p->researcher->last_name }}</td><td>{{ $p->research_topic }}</td>
            <td>{{ $p->start_date->format('Y-m-d') }} - {{ $p->end_date->format('Y-m-d') }}@if($p->fee_amount > 0) · {{ $p->fee_currency }} {{ $p->fee_amount }}@if($p->fee_paid) ({{ __('paid') }})@endif @endif</td>
            <td>{{ $p->status->label($lang) }}@if($p->rejection_reason) · {{ $p->rejection_reason }}@endif</td>
            <td>@if($p->status->notation === 'pending')
                    <form method="post" action="{{ route('jurisdiction.naz.step', ['approve', $p->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Approve') }}</button></form>
                    <form method="post" action="{{ route('jurisdiction.naz.step', ['reject', $p->id]) }}" style="display: inline">@csrf<label for="pr-{{ $loop->index }}">{{ __('Why') }}</label> <input id="pr-{{ $loop->index }}" name="reason" size="14"> <button type="submit">{{ __('Reject') }}</button></form>
                @elseif(in_array($p->status->notation, ['approved', 'active'], true))
                    <form method="post" action="{{ route('jurisdiction.naz.step', ['revoke', $p->id]) }}" style="display: inline">@csrf<label for="pv-{{ $loop->index }}">{{ __('Why') }}</label> <input id="pv-{{ $loop->index }}" name="reason" size="14"> <button type="submit">{{ __('Revoke') }}</button></form>
                @endif</td></tr>
        @empty<tr><td colspan="6">{{ __('None.') }}</td></tr>@endforelse</tbody></table>
    <details><summary>{{ __('New research permit') }}</summary><form method="post" action="{{ route('jurisdiction.naz.step', 'permit') }}">@csrf
        <p><label for="p-researcher">{{ __('Researcher') }}</label> <select id="p-researcher" name="researcher_id" required>@foreach($researchers as $r)<option value="{{ $r->id }}">{{ $r->last_name }}, {{ $r->first_name }}</option>@endforeach</select>
            <label for="p-type">{{ __('Type') }}</label> <select id="p-type" name="permit_type">{!! $options($permitTypes) !!}</select></p>
        <p><label for="p-topic">{{ __('Topic') }}</label> <input id="p-topic" name="research_topic" size="60" required></p>
        <p><label for="p-purpose">{{ __('Purpose') }}</label><br><textarea id="p-purpose" name="research_purpose" rows="2" cols="70"></textarea></p>
        <p><label for="p-start">{{ __('From') }}</label> <input id="p-start" name="start_date" type="date" required> <label for="p-end">{{ __('to') }}</label> <input id="p-end" name="end_date" type="date" required></p>
        <p><label for="p-fee">{{ __('Fee') }}</label> <input id="p-fee" name="fee_amount" type="number" step="0.01" min="0"> <label for="p-cur">{{ __('Currency') }}</label> <input id="p-cur" name="fee_currency" size="3" maxlength="3" value="USD">
            <label><input type="checkbox" name="fee_paid" value="1"> {{ __('paid') }}</label> <label for="p-receipt">{{ __('Receipt') }}</label> <input id="p-receipt" name="fee_receipt" size="12">
            <label for="p-paid">{{ __('on') }}</label> <input id="p-paid" name="payment_date" type="date"></p>
        @if($repositories->isNotEmpty())<fieldset><legend>{{ __('Repositories it opens (none: all the permit type allows)') }}</legend>@foreach($repositories as $r)<label><input type="checkbox" name="repository_ids[]" value="{{ $r->id }}"> {{ \App\Relations\EntityKinds::label($r) }}</label> @endforeach</fieldset>@endif
        <p><label for="p-restr">{{ __('Restrictions') }}</label><br><textarea id="p-restr" name="restrictions" rows="2" cols="70"></textarea></p>
        <button type="submit">{{ __('Save') }}</button></form></details>

    <h2>{{ __('Records schedules') }}</h2>
    @forelse($schedules as $s)
        <details><summary>{{ $s->schedule_number }} · {{ $s->agency_name }} · {{ $s->record_series }} · {{ $s->retention_active_years }}+{{ $s->retention_semi_active_years }} · {{ $s->disposalAction->label($lang) }} · {{ $s->status->label($lang) }}</summary>
            <form method="post" action="{{ route('jurisdiction.naz.step', ['schedule', $s->id]) }}">@csrf @include('jurisdiction._naz-schedule-fields', ['s' => $s, 'x' => 's'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('New records schedule') }}</summary><form method="post" action="{{ route('jurisdiction.naz.step', 'schedule') }}">@csrf @include('jurisdiction._naz-schedule-fields', ['s' => null, 'x' => 'sn'])<button type="submit">{{ __('Save') }}</button></form></details>

    <h2>{{ __('Transfers') }}</h2>
    @forelse($transfers as $t)
        <details><summary>{{ $t->transfer_number }} · {{ $t->transferring_agency }} · {{ $t->transferType->label($lang) }} · {{ $t->status->label($lang) }}@if($t->accession_number) · {{ $t->accession_number }}@endif</summary>
            <form method="post" action="{{ route('jurisdiction.naz.step', ['transfer', $t->id]) }}">@csrf @include('jurisdiction._naz-transfer-fields', ['t' => $t, 'x' => 't'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('New transfer') }}</summary><form method="post" action="{{ route('jurisdiction.naz.step', 'transfer') }}">@csrf @include('jurisdiction._naz-transfer-fields', ['t' => null, 'x' => 'tn'])<button type="submit">{{ __('Save') }}</button></form></details>
@endsection
