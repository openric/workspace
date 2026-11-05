{{-- OpenRiC Workspace - flag a description for prioritised capture (roadmap phase 17d3), after Heratio's endangered/flag (ahg-semantic-search). Needs $record, $item, $risks, $urgencies, $statuses. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $item ? __('Update at-risk flag') : __('Flag a record as at-risk'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $item ? __('Update at-risk flag') : __('Flag a record as at-risk') }}</h1>
    <p>{{ __('Record') }}: <a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record, $lang) }}</a></p>
    <p>{{ __('Record why this item should be captured sooner rather than later, and how urgent it is.') }}</p>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if($item)<p>{{ __('This item already has an at-risk flag. Saving will update the existing flag.') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('heritage.flag.store') }}">@csrf
        <input type="hidden" name="record" value="{{ $record->id }}">
        <p><label for="hf-risk">{{ __('Risk category') }}</label> <select id="hf-risk" name="risk">@foreach($risks as $r)<option value="{{ $r->notation }}" @selected(old('risk', $item?->risk?->notation ?? 'other') === $r->notation)>{{ $r->label($lang) }}</option>@endforeach</select></p>
        <p><label for="hf-urgency">{{ __('Urgency') }}</label> <select id="hf-urgency" name="urgency">@foreach($urgencies as $u)<option value="{{ $u->notation }}" @selected(old('urgency', $item?->urgency?->notation ?? 'medium') === $u->notation)>{{ $u->label($lang) }}</option>@endforeach</select>
            <br><small>{{ __('Urgency is a curatorial judgement of how soon an item should be captured, not a forecast of how soon it might be lost.') }}</small></p>
        <p><label for="hf-status">{{ __('Capture status') }}</label> <select id="hf-status" name="capture_status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected(old('capture_status', $item?->captureStatus?->notation ?? 'flagged') === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select></p>
        <p><label for="hf-reason">{{ __('Reason') }}</label><br><textarea id="hf-reason" name="reason" rows="5" cols="70">{{ old('reason', $item?->reason) }}</textarea>
            <br><small>{{ __('A factual note on why this item is at risk and why capture is a priority. State what is known; avoid alarmist or speculative claims.') }}</small></p>
        <button type="submit">{{ $item ? __('Update flag') : __('Flag for capture') }}</button> <a href="{{ route('records.show', $record) }}">{{ __('Cancel') }}</a>
    </form>
@endsection
