{{-- OpenRiC Workspace - one name to resolve: its context, ranked candidates and their evidence, and the decisions open (Heratio's authority resolution review; roadmap phase 17b2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Resolve :name', ['name' => $mention->value()]))
@section('content')
    @php
        $dimensions = ['temporal' => __('Dates'), 'geographic' => __('Places'), 'relational' => __('Relations'), 'role' => __('Words of role'), 'conflict' => __('Contradiction'),
            'co_occurring' => __('People named near it'), 'hierarchical' => __('Broader places'), 'scale' => __('Scale'), 'document_prior' => __('Places the fonds uses')];
        $signals = ['match' => __('agrees'), 'conflict' => __('contradicts'), 'silent' => __('does not agree'), 'absent' => __('nothing to go on')];
    @endphp
    <p><a href="{{ route('authority.queue') }}">{{ __('Names to resolve') }}</a></p>
    <h1>{{ $mention->value() }} <span class="meta">({{ $mention->isPlace() ? __('place') : ($mention->label() === 'PERSON' ? __('person') : __('organisation')) }})</span></h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Named in') }} <a href="{{ route('records.show', $mention->record) }}">{{ \App\Relations\EntityKinds::label($mention->record) }}</a>.
        {{ $mention->state->label(\App\Support\Vocab::languages()) }}.
        @if($mention->proposal->inference){{ __('Found by :model', ['model' => $mention->ner_model ?? $mention->proposal->inference->model]) }}@if($mention->confidence !== null), {{ __('confidence :c', ['c' => number_format($mention->confidence, 2)]) }}@endif.@else{{ __('Proposed through :origin.', ['origin' => $mention->proposal->origin_label]) }}@endif
        @if($mention->assignedTo){{ __('Given to :name.', ['name' => $mention->assignedTo->name]) }}@endif</p>

    <h2>{{ __('Where it stands') }}</h2>
    @if($mention->offset_start !== null)
        <blockquote style="white-space: pre-line">…{{ $mention->text_before }}<mark>{{ $mention->value() }}</mark>{{ $mention->text_after }}…</blockquote>
        @if($mention->occurrences > 1)<p class="meta">{{ __('The name appears :n times; this is the first.', ['n' => $mention->occurrences]) }}</p>@endif
    @else
        <p class="meta">{{ __('The name was not found in the description or the transcripts of its pages.') }}</p>
    @endif
    <p class="meta">
        @if($mention->nearby_dates){{ __('Dates near it') }}: {{ collect($mention->nearby_dates)->pluck('value')->unique()->join(', ') }}. @endif
        @if($mention->nearby_places){{ __('Places near it') }}: {{ collect($mention->nearby_places)->pluck('value')->unique()->join(', ') }}. @endif
        @if($mention->co_occurring){{ __('Others named near it') }}: {{ collect($mention->co_occurring)->pluck('value')->unique()->join(', ') }}. @endif
        @if($mention->role_tokens){{ __('Words of role') }}: {{ collect($mention->role_tokens)->pluck('token')->unique()->join(', ') }}.@endif</p>

    <h2>{{ __('Candidates') }}</h2>
    @forelse($mention->candidates as $c)
        <section style="border: 1px solid var(--line); padding: .2rem 1rem; margin-bottom: .8rem">
            <h3 style="font-size: 1.05rem">{{ $c->rank }}. <a href="{{ route($c->candidate_table === 'agents' ? 'agents.show' : 'places.show', $c->candidate_id) }}">{{ $c->display_name }}</a>
                <span class="meta">{{ __('score :s (name :n)', ['s' => number_format((float) $c->composite, 2), 'n' => number_format($c->name_similarity, 2)]) }}</span></h3>
            <ul class="meta" style="margin: .2rem 0">@foreach($c->signals ?? [] as $dimension => $signal)<li>{{ $dimensions[$dimension] ?? $dimension }}: <strong>{{ $signals[$signal] ?? $signal }}</strong>@php($why = $c->evidence[$dimension] ?? [])
                @if(! empty($why['overlapping_years'])) ({{ implode(', ', $why['overlapping_years']) }})@elseif(! empty($why['overlaps'])) ({{ collect($why['overlaps'])->map(fn ($o) => implode(' / ', array_filter($o, 'is_string')))->join('; ') }})@elseif(! empty($why['matched_tokens'])) ({{ implode(', ', $why['matched_tokens']) }})@elseif(! empty($why['mention_years_after_end'])) ({{ __('named in :years, after its end', ['years' => implode(', ', $why['mention_years_after_end'])]) }})@endif</li>@endforeach</ul>
            @if($open)<form method="post" action="{{ route('authority.link', [$mention, $c]) }}">@csrf<button type="submit">{{ __('Link to this one') }}</button></form>@endif
        </section>
    @empty
        <p>{{ __('No authority record or place of the institution has this name.') }}</p>
    @endforelse

    @if($open)
        <h2>{{ __('Or') }}</h2>
        <form method="post" action="{{ route('authority.link-different', $mention) }}">@csrf
            <label for="ar-other">{{ $mention->isPlace() ? __('Link to another place (its address or identifier)') : __('Link to another authority record (its address or identifier)') }}</label>
            <input id="ar-other" name="entity" required pattern="[0-9a-fA-F-]{36}" placeholder="{{ __('Its identifier, as in the address of its page') }}">
            <button type="submit">{{ __('Link') }}</button></form>
        <p><a href="{{ route('authority.create-form', $mention) }}">{{ $mention->isPlace() ? __('Make a new place for it') : __('Make a new authority record for it') }}</a></p>
        <form method="post" action="{{ route('authority.park', $mention) }}">@csrf<label for="ar-park">{{ __('Park it, saying why') }}</label><input id="ar-park" name="reason" required maxlength="2000"> <button type="submit">{{ __('Park') }}</button></form>
        <form method="post" action="{{ route('authority.reject', $mention) }}">@csrf<label for="ar-reject">{{ __('Reject it: not the name of a person, organisation or place') }}</label><input id="ar-reject" name="reason" maxlength="2000" placeholder="{{ __('Why, to help the model learn') }}"> <button type="submit">{{ __('Reject') }}</button></form>
        @if($mention->state->notation === 'parked')<form method="post" action="{{ route('authority.unpark', $mention) }}">@csrf<button type="submit">{{ __('Take it up again, finding its candidates afresh') }}</button></form>@endif
        @if($archivists->isNotEmpty())
            <form method="post" action="{{ route('authority.assign') }}">@csrf<input type="hidden" name="mentions[]" value="{{ $mention->id }}">
                <label for="ar-give">{{ __('Give it to') }}</label><select id="ar-give" name="archivist">@foreach($archivists as $a)<option value="{{ $a->id }}" @selected($a->id === $mention->assigned_to_id)>{{ $a->name }}</option>@endforeach</select>
                <input name="note" maxlength="2000" aria-label="{{ __('A note for them') }}" placeholder="{{ __('A note for them') }}"> <button type="submit">{{ __('Give it') }}</button></form>
        @endif
    @endif

    @if($mention->decisions->isNotEmpty())
        <h2>{{ __('Decisions') }}</h2>
        <ul>@foreach($mention->decisions as $d)<li>{{ $d->decision->label(\App\Support\Vocab::languages()) }}, {{ __('by :name on :date', ['name' => $d->user->name, 'date' => $d->decided_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) }}@if($d->reason): {{ $d->reason }}@endif
            <span class="meta">{{ trans_choice('(:count candidate seen)|(:count candidates seen)', count($d->candidates_snapshot), ['count' => count($d->candidates_snapshot)]) }}</span></li>@endforeach</ul>
    @endif
@endsection
