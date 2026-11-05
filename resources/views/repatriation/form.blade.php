{{-- OpenRiC Workspace - register or amend a repatriation claim (roadmap phase 17d2), after Heratio's repatriation/form (ahg-semantic-search). Needs $claim (null for a new one), $record, $statuses, $agents, $places. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $claim ? __('Edit repatriation claim') : __('Register a repatriation claim'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $claim ? __('Edit repatriation claim') : __('Register a repatriation claim') }}</h1>
    <p>{{ __('Object') }}: <a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record, $lang) }}</a></p>
    <p class="meta">{{ __(\App\Repatriation\Claims::DISCLAIMER) }}</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ $claim ? route('repatriation.update', $claim) : route('repatriation.store') }}">@csrf
        @unless($claim)<input type="hidden" name="record" value="{{ $record->id }}">@endunless
        <p><label for="rc-community">{{ __('Claimant community or nation') }}</label><br><input id="rc-community" name="claimant_community" maxlength="512" size="60" value="{{ old('claimant_community', $claim?->claimant_community) }}">
            <label for="rc-agent">{{ __('Its authority record') }}</label> <select id="rc-agent" name="claimant_agent_id"><option value="">{{ __('None') }}</option>@foreach($agents as $a)<option value="{{ $a['id'] }}" @selected(old('claimant_agent_id', $claim?->claimant_agent_id) === $a['id'])>{{ $a['name'] }}</option>@endforeach</select></p>
        <p><label for="rc-origin">{{ __('Place / region of origin') }}</label><br><input id="rc-origin" name="origin_place" maxlength="512" size="60" value="{{ old('origin_place', $claim?->origin_place) }}">
            <label for="rc-place">{{ __('Its place') }}</label> <select id="rc-place" name="origin_place_id"><option value="">{{ __('None') }}</option>@foreach($places as $p)<option value="{{ $p['id'] }}" @selected(old('origin_place_id', $claim?->origin_place_id) === $p['id'])>{{ $p['name'] }}</option>@endforeach</select></p>
        <p><label for="rc-holder">{{ __('Current holder') }}</label><br><input id="rc-holder" name="current_holder" maxlength="512" size="60" value="{{ old('current_holder', $claim?->current_holder) }}" placeholder="{{ __('The description\'s repository, when left empty') }}"></p>
        <p><label for="rc-status">{{ __('Stage of dialogue') }}</label> <select id="rc-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" title="{{ $s->labels->whereIn('language', $lang)->first()?->definition }}" @selected(old('status', $claim?->status->notation ?? 'registered') === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select></p>
        <p><label for="rc-evidence">{{ __('Documented evidence') }}</label><br><textarea id="rc-evidence" name="evidence_summary" rows="5" cols="70">{{ old('evidence_summary', $claim?->evidence_summary) }}</textarea></p>
        <p><label for="rc-contact">{{ __('Contact') }}</label> <input id="rc-contact" name="contact" maxlength="512" size="40" value="{{ old('contact', $claim?->contact) }}">
            <label for="rc-email">{{ __('Contact email') }}</label> <input id="rc-email" name="contact_email" type="email" maxlength="255" value="{{ old('contact_email', $claim?->contact_email) }}"></p>
        <p><label for="rc-notes">{{ __('Notes') }}</label><br><textarea id="rc-notes" name="notes" rows="3" cols="70">{{ old('notes', $claim?->notes) }}</textarea></p>
        <button type="submit">{{ __('Save') }}</button> <a href="{{ $claim ? route('repatriation.dialogue', $claim) : route('records.show', $record) }}">{{ __('Cancel') }}</a>
    </form>
@endsection
