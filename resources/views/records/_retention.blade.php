{{-- OpenRiC Workspace - a record's retention and disposal (roadmap phase 13b, ADR 0015), after Heratio's record retention panel: its disposal class and due date, reviews, disposals; give a class, another date, schedule a review, start a disposal. Needs: $record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($assignment = \App\Models\RecordDisposalClass::where('record_resource_id', $record->id)->with('disposalClass.schedule', 'disposalClass.action')->first())
@php($classes = \App\Models\DisposalClass::whereHas('schedule', fn ($q) => $q->where('institution_id', $record->institution_id)->whereHas('status', fn ($q) => $q->where('iri', \App\Support\Vocab::BASE.'retention-schedule-status/approved')))->where('is_active', true)->with('schedule')->orderBy('reference')->get())
@php($disposals = \App\Models\DisposalAction::where('record_resource_id', $record->id)->with('action', 'status')->orderByDesc('initiated_at')->get())
@php($reviews = \App\Models\RetentionReview::where('record_resource_id', $record->id)->with('decision')->orderByDesc('due_on')->get())
<section id="retention">
    <h2>{{ __('Retention and disposal') }}</h2>
    @error('retention')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($assignment)
        <p>{{ __('Disposal class') }}: <strong>{{ $assignment->disposalClass->reference }} {{ $assignment->disposalClass->title }}</strong> ({{ $assignment->disposalClass->schedule->reference }} v{{ $assignment->disposalClass->schedule->version }}) ·
            {{ __('then') }}: {{ $assignment->disposalClass->action->label($lang) }} ·
            @php($due = $assignment->override_due_on ?? $assignment->disposal_due_on)
            {{ __('Due') }}: {{ $due?->format('Y-m-d') ?? __('no date') }}@if($assignment->override_reason) <span class="meta">({{ $assignment->override_reason }})</span>@endif</p>
    @else<p>{{ __('No disposal class.') }}</p>@endif
    <details><summary>{{ __('Give it a disposal class') }}</summary>
        <form method="post" action="{{ route('records.retention.assign', $record) }}">@csrf
            <p><label for="dc">{{ __('Disposal class') }}</label> <select id="dc" name="disposal_class_id"><option value="">{{ __('None') }}</option>
                @foreach($classes as $c)<option value="{{ $c->id }}" @selected($assignment?->disposal_class_id === $c->id)>{{ $c->schedule->reference }}: {{ $c->reference }} {{ $c->title }}</option>@endforeach</select>
                <label for="ev">{{ __('Day of the event it runs from (an event class)') }}</label> <input id="ev" type="date" name="event_date"></p>
            <button type="submit">{{ __('Save') }}</button></form>
        @if($assignment)
            <form method="post" action="{{ route('records.retention.assign', $record) }}">@csrf <input type="hidden" name="override" value="1">
                <p><label for="od">{{ __('Due on another day') }}</label> <input id="od" type="date" name="override_due_on" value="{{ $assignment->override_due_on?->format('Y-m-d') }}">
                    <label for="or">{{ __('Why') }}</label> <input id="or" name="override_reason" size="40" value="{{ $assignment->override_reason }}"></p>
                <button type="submit">{{ __('Save') }}</button></form>
        @endif
    </details>
    <details><summary>{{ __('Schedule a review') }}</summary>
        <form method="post" action="{{ route('records.retention.review', $record) }}">@csrf <label for="rd">{{ __('Due on') }}</label> <input id="rd" type="date" name="due_on" required> <button type="submit">{{ __('Schedule') }}</button></form>
    </details>
    <details><summary>{{ __('Start a disposal') }}</summary>
        <form method="post" action="{{ route('records.retention.initiate', $record) }}">@csrf
            <p><label for="da">{{ __('Action') }}</label> <select id="da" name="action">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'disposal-action-type/')->get() as $a)<option value="{{ $a->notation }}" @selected($assignment?->disposalClass->action->notation === $a->notation)>{{ $a->label($lang) }}</option>@endforeach</select></p>
            <p><label for="dr">{{ __('Reason') }}</label><br><textarea id="dr" name="reason" rows="2" cols="60"></textarea></p>
            <button type="submit">{{ __('Start') }}</button></form>
    </details>
    @if($reviews->isNotEmpty())<p>{{ __('Reviews') }}: @foreach($reviews as $r){{ $r->due_on->format('Y-m-d') }} ({{ $r->decision?->label($lang) ?? __('open') }})@if(! $loop->last), @endif @endforeach</p>@endif
    @if($disposals->isNotEmpty())<p>{{ __('Disposals') }}: @foreach($disposals as $d)<a href="{{ route('disposals.show', $d) }}">{{ $d->action->label($lang) }}, {{ mb_strtolower($d->status->label($lang)) }}</a>@if(! $loop->last), @endif @endforeach</p>@endif
</section>
