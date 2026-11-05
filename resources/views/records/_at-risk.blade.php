{{-- OpenRiC Workspace - a record's at-risk flag (roadmap phase 17d3), with a way to flag it or change the flag. Needs: $record. Shown to who may change the record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($atRisk = \App\Models\EndangeredItem::where('record_resource_id', $record->id)->with('risk.labels', 'urgency.labels', 'captureStatus.labels')->first())
<section id="at-risk">
    <h2>{{ __('Heritage at risk') }} @include('description._ric', ['term' => null])</h2>
    @if($atRisk)<p>{{ $atRisk->risk->label(\App\Support\Vocab::languages()) }} · {{ $atRisk->urgency->label(\App\Support\Vocab::languages()) }} · {{ $atRisk->captureStatus->label(\App\Support\Vocab::languages()) }}</p>
        @if($atRisk->reason)<p style="white-space: pre-line">{{ $atRisk->reason }}</p>@endif@else<p>{{ __('Not flagged.') }}</p>@endif
    <p><a href="{{ route('heritage.flag', ['record' => $record->id]) }}">{{ $atRisk ? __('Edit flag') : __('Flag for capture') }}</a></p>
</section>
