{{-- OpenRiC Workspace - loans between the members of the institution's union, and their figures (Heratio's ahg-federation loans and loan analytics; roadmap phase 16d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Loans'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($labels = $statuses->keyBy('notation'))
    <h1>{{ __('Loans') }}</h1>
    <p><a href="{{ route('federation.admin') }}#union">{{ __('Union catalogue') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Requests to borrow an item between members of the union, for an exhibition, research or conservation: requested, then approved or declined by the holder, in transit while on loan, and returned.') }}</p>
    <h2>{{ __('Figures') }}</h2>
    <dl>
        <dt>{{ __('Loans') }}</dt><dd>{{ $report['total'] }} · {{ collect($report['by_status'])->map(fn ($n, $s) => $labels[$s]?->label($lang).' '.$n)->join(', ') }}</dd>
        <dt>{{ __('From our side') }}</dt><dd>{{ __(':out asked of others, :in asked of us', ['out' => $report['outgoing'], 'in' => $report['incoming']]) }}</dd>
        <dt>{{ __('Approved') }}</dt><dd>{{ $report['approval_rate'] !== null ? $report['approval_rate'].'%' : '-' }} · {{ __('days to a decision: :days', ['days' => $report['average_days_to_decide'] ?? '-']) }}</dd>
        @if($report['top_items'])<dt>{{ __('Items most asked for') }}</dt><dd>{{ collect($report['top_items'])->map(fn ($n, $t) => "$t ($n)")->join('; ') }}</dd>@endif
        @if($report['top_requesting'])<dt>{{ __('Members asking most') }}</dt><dd>{{ collect($report['top_requesting'])->map(fn ($n, $m) => "$m ($n)")->join('; ') }}</dd>@endif
    </dl>
    <form method="get" action="{{ route('federation.loans') }}"><label for="ls">{{ __('Status') }}</label><select id="ls" name="status" onchange="this.form.submit()"><option value="">{{ __('Every status') }}</option>@foreach($statuses as $st)<option value="{{ $st->notation }}" @selected($status === $st->notation)>{{ $st->label($lang) }}</option>@endforeach</select><noscript><button type="submit">{{ __('Show') }}</button></noscript></form>
    <table><thead><tr><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('To') }}</th><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($loans as $l)<tr><td>@if($l->record)<a href="{{ route('records.show', $l->record) }}">{{ $l->item_title }}</a>@else{{ $l->item_title }}@endif @if($l->purpose)<br><small>{{ $l->purpose }}</small>@endif</td>
            <td>{{ $l->holding?->name }}</td><td>{{ $l->requesting?->name }}</td><td>{{ $l->needed_from?->format('Y-m-d') }}@if($l->needed_to) {{ __('to :date', ['date' => $l->needed_to->format('Y-m-d')]) }}@endif</td>
            <td>{{ $l->status->label($lang) }}@if($l->notes)<br><small style="white-space: pre-line">{{ $l->notes }}</small>@endif
                @foreach($transitions[$l->status->notation] ?? [] as $to)<form method="post" action="{{ route('federation.loans.move', [$l, $to]) }}">@csrf<input name="note" aria-label="{{ __('Note') }}" placeholder="{{ __('Note') }}"><button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ $labels[$to]?->label($lang) }}</button></form>@endforeach</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    @if($members->count() > 1)
        <details><summary>{{ __('Request a loan') }}</summary><form method="post" action="{{ route('federation.loans.store') }}">@csrf
            <label for="ln-holder">{{ __('Held by') }}</label><select id="ln-holder" name="holding_member_id">@foreach($members as $m)<option value="{{ $m->id }}">{{ $m->name }}@if($m->is_self) ({{ __('this institution') }})@endif</option>@endforeach</select>
            <label for="ln-asker">{{ __('Asked for by') }}</label><select id="ln-asker" name="requesting_member_id">@foreach($members as $m)<option value="{{ $m->id }}" @selected(! $m->is_self)>{{ $m->name }}@if($m->is_self) ({{ __('this institution') }})@endif</option>@endforeach</select>
            <label for="ln-unit">{{ __('Our unit of description, where we hold it (its identifier, a UUID)') }}</label><input id="ln-unit" name="record_resource_id">
            <label for="ln-ref">{{ __('Or the holder\'s reference for it') }}</label><input id="ln-ref" name="item_ref">
            <label for="ln-title">{{ __('Its title') }}</label><input id="ln-title" name="item_title">
            <label for="ln-purpose">{{ __('Purpose') }}</label><input id="ln-purpose" name="purpose" placeholder="{{ __('exhibition, research, conservation') }}">
            <label for="ln-from">{{ __('Needed from') }}</label><input id="ln-from" name="needed_from" type="date">
            <label for="ln-to">{{ __('Needed until') }}</label><input id="ln-to" name="needed_to" type="date">
            <label for="ln-notes">{{ __('Notes') }}</label><textarea id="ln-notes" name="notes" rows="2"></textarea>
            <button type="submit">{{ __('Request it') }}</button></form></details>
    @else
        <p class="meta">{{ __('A loan is between members of the union: add a member first.') }}</p>
    @endif
@endsection
