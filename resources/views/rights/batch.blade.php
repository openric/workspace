{{-- OpenRiC Workspace - a right or a Label for the archival descriptions on the clipboard (roadmap phase 2; Heratio's batch rights assignment, after AtoM's extendedRights/batchAction). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Rights for the clipboard'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($options = fn (string $vocabulary, bool $optional) => ($optional ? '<option value="">'.e(__('Not given')).'</option>' : '').\App\Models\Concept::choices($vocabulary)->get()->map(fn ($c) => '<option value="'.e($c->notation).'">'.e($c->label($lang)).'</option>')->join(''))
    <p><a href="{{ route('rights.index') }}">{{ __('Rights') }}</a></p>
    <h1>{{ __('Rights for the clipboard') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('batch')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($records->isEmpty())
        <p>{{ __('There are no archival descriptions on the clipboard. Add them from their pages with "Add to clipboard".') }}</p>
    @else
        <p>{{ __('For these :count archival descriptions; one you may not change is skipped.', ['count' => $records->count()]) }}</p>
        <ul>@foreach($records->take(50) as $record)<li><a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record) }}</a></li>@endforeach</ul>
        <h2>{{ __('Add a right') }}</h2>
        <form method="post" action="{{ route('rights.batch.store') }}">
            @csrf
            <input type="hidden" name="what" value="right">
            <label for="b-basis">{{ __('Basis') }}</label><select id="b-basis" name="basis">{!! $options(\App\Rights\RightsService::BASES, false) !!}</select>
            <label for="b-statement">{{ __('Rights statement') }} (RightsStatements.org)</label><select id="b-statement" name="statement">{!! $options(\App\Rights\RightsService::STATEMENTS, true) !!}</select>
            <label for="b-licence">{{ __('Licence') }} (Creative Commons)</label><select id="b-licence" name="licence">{!! $options(\App\Rights\RightsService::LICENCES, true) !!}</select>
            <label for="b-starts">{{ __('In force from') }}</label><input id="b-starts" name="starts_on" type="date">
            <label for="b-ends">{{ __('In force until') }}</label><input id="b-ends" name="ends_on" type="date">
            <label style="font-weight: normal"><input type="checkbox" name="with_instantiations" value="1" style="width: auto"> {{ __('Their instantiations too') }}</label>
            <button type="submit">{{ __('Add the right to each') }}</button>
        </form>
        <h2>{{ __('Apply a Label') }}</h2>
        <form method="post" action="{{ route('rights.batch.store') }}">
            @csrf
            <input type="hidden" name="what" value="label">
            <label for="b-label">{{ __('Label') }}</label>
            <select id="b-label" name="label">
                @foreach(app(\App\Rights\CommunityLabelService::class)->choices() as $labels)
                    <optgroup label="{{ $labels->first()->broader->label($lang) }}">@foreach($labels as $choice)<option value="{{ $choice->notation }}">{{ $choice->label($lang) }}</option>@endforeach</optgroup>
                @endforeach
            </select>
            <button type="submit">{{ __('Apply the Label to each') }}</button>
        </form>
    @endif
@endsection
