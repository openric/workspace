{{-- OpenRiC Workspace - a record's security classification (roadmap phase 18c, ADR 0016). Needs: $record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($clearances = app(\App\Rights\Clearances::class))
@php($effective = $clearances->effective($record))
@php($levels = $clearances->levels($record->institution_id))
@php($compartments = \App\Models\SecurityCompartment::where(['institution_id' => $record->institution_id, 'is_active' => true])->orderBy('code')->get())
<section id="classification">
    <h2>{{ __('Security classification') }} @include('description._ric', ['term' => null])</h2>
    @error('clearance')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>@if($effective){{ $effective->level->name }}@if($effective->record_resource_id !== $record->id) <span class="meta">({{ __('from :record', ['record' => $effective->record?->iri]) }})</span>@endif
        @if($effective->declassify_on) · {{ __('declassified on :date', ['date' => $effective->declassify_on->format('Y-m-d')]) }}@endif @if($effective->handling_instructions)<br>{{ $effective->handling_instructions }}@endif
        @else{{ __('Not classified') }}@endif</p>
    <details><summary>{{ __('Classify') }}</summary><form method="post" action="{{ route('records.classification', $record) }}">@csrf
        <label for="cl-level">{{ __('Level') }}</label> @include('description._ric', ['term' => null]) <select id="cl-level" name="level_id">@foreach($levels->where('level', '>', 0) as $l)<option value="{{ $l->id }}">{{ $l->level }} {{ $l->name }}</option>@endforeach</select>
        <label for="cl-review">{{ __('Review on') }}</label> @include('description._ric', ['term' => null]) <input id="cl-review" name="review_on" type="date">
        <label for="cl-on">{{ __('Declassify on') }}</label> @include('description._ric', ['term' => null]) <input id="cl-on" name="declassify_on" type="date">
        <label for="cl-to">{{ __('to') }}</label> @include('description._ric', ['term' => null]) <select id="cl-to" name="declassify_to_level_id"><option value="">{{ __('No classification') }}</option>@foreach($levels->where('level', '>', 0) as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select><br>
        @if($compartments->isNotEmpty())<fieldset><legend>{{ __('Compartments') }}</legend>@foreach($compartments as $c)<label><input type="checkbox" name="compartments[]" value="{{ $c->id }}"> {{ $c->code }} {{ $c->name }}</label> @endforeach</fieldset>@endif
        <input type="hidden" name="inherit_to_children" value="0"><label><input type="checkbox" name="inherit_to_children" value="1" checked> {{ __('Everything inside it too') }}</label><br>
        <label for="cl-reason">{{ __('Reason') }}</label> @include('description._ric', ['term' => null]) <input id="cl-reason" name="reason" size="50"> <label for="cl-just">{{ __('Justification') }}</label> @include('description._ric', ['term' => null]) <input id="cl-just" name="justification" size="40"><br>
        <label for="cl-handling">{{ __('Handling instructions') }}</label> @include('description._ric', ['term' => null]) <input id="cl-handling" name="handling_instructions" size="60"> <button type="submit">{{ __('Classify') }}</button></form></details>
    @if($effective && $effective->record_resource_id === $record->id)
        <form method="post" action="{{ route('records.classification', $record) }}">@csrf<input type="hidden" name="declassify" value="1">
            <label for="dc-reason">{{ __('Declassify, giving the reason') }}</label> <input id="dc-reason" name="reason" size="40" required> <button type="submit">{{ __('Declassify') }}</button></form>
    @endif
</section>
