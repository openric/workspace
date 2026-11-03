{{-- OpenRiC Workspace - two archival descriptions that may be the same, side by side, and what to do (Heratio's ahg-dedupe compare and merge; roadmap phase 22b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Compare'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('duplicates.index') }}">{{ __('Duplicate descriptions') }}</a></p>
    <h1>{{ __('Are these the same?') }}</h1>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ $pair->ruleType->label($lang) }} · {{ number_format($pair->similarity_score * 100) }}% · {{ $pair->status->label($lang) }}</p>
    @php($fields = collect([...array_keys(app(\App\Versions\Versions::class)->diff([], $a)), ...array_keys(app(\App\Versions\Versions::class)->diff([], $b))])->unique()->sort()->values())
    @php([$fa, $fb] = [app(\App\Versions\Versions::class)->diff([], $a), app(\App\Versions\Versions::class)->diff([], $b)])
    <table><thead><tr><th scope="col">{{ __('Field') }}</th><th scope="col"><a href="{{ route('records.show', $pair->recordA) }}">{{ \App\Relations\EntityKinds::label($pair->recordA) }}</a></th><th scope="col"><a href="{{ route('records.show', $pair->recordB) }}">{{ \App\Relations\EntityKinds::label($pair->recordB) }}</a></th></tr></thead>
        <tbody>@foreach($fields as $f)@php([$x, $y] = [implode("\n", $fa[$f]['after'] ?? []), implode("\n", $fb[$f]['after'] ?? [])])
            <tr @if($x !== $y) class="differs" @endif><th scope="row"><code>{{ $f }}</code></th><td>{!! nl2br(e($x)) !!}</td><td>{!! nl2br(e($y)) !!}</td></tr>@endforeach</tbody></table>
    @if($pair->status->notation !== 'merged')
        <h2>{{ __('Decide') }}</h2>
        <form method="post" action="{{ route('duplicates.step', [$pair, 'merge']) }}" onsubmit="return confirm('{{ __('Merge? The other is deleted as merged, its IRI pointing to the one kept.') }}')">@csrf
            <fieldset><legend>{{ __('Keep') }}</legend>
                <label><input type="radio" name="kept" value="{{ $pair->record_a_id }}" required> {{ \App\Relations\EntityKinds::label($pair->recordA) }}</label>
                <label><input type="radio" name="kept" value="{{ $pair->record_b_id }}"> {{ \App\Relations\EntityKinds::label($pair->recordB) }}</label></fieldset>
            <button type="submit">{{ __('Merge the other into it') }}</button></form>
        <form method="post" action="{{ route('duplicates.step', [$pair, 'dismiss']) }}">@csrf<label for="notes">{{ __('Why they are different') }}</label> <input id="notes" name="notes" size="40"> <button type="submit">{{ __('Not duplicates') }}</button></form>
        <form method="post" action="{{ route('duplicates.step', [$pair, 'confirm']) }}">@csrf<button type="submit">{{ __('Confirm, merge later') }}</button></form>
    @endif
@endsection
