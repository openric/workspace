{{-- OpenRiC Workspace - the steps of a privacy entry: its status, and its register's own steps (roadmap phase 18a). Needs: $entry, $register, $definition, $lang. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($step = fn (string $s) => route('privacy.step', [$register, $entry->id, $s]))
@if(isset($definition['status']) && ! in_array($register, ['processing', 'assessments'], true))
    <form method="post" action="{{ $step('status') }}">@csrf <label for="st">{{ __('Status') }}</label> @include('description._ric', ['term' => null])
        <select id="st" name="status">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.$definition['status'].'/')->get() as $c)<option value="{{ $c->notation }}" @selected($entry->status->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
        <button type="submit">{{ __('Set') }}</button></form>
@endif
@switch($register)
    @case('processing')
        @if($entry->submitted_at)<p class="meta">{{ __('Submitted by :name on :date', ['name' => $entry->submittedBy?->name, 'date' => $entry->submitted_at->format('Y-m-d')]) }}@if($entry->decided_at); {{ __('decided by :name on :date', ['name' => $entry->decidedBy?->name, 'date' => $entry->decided_at->format('Y-m-d')]) }}@endif @if($entry->rejection_reason): {{ $entry->rejection_reason }}@endif</p>@endif
        @if(in_array($entry->status->notation, ['draft', 'rejected'], true))<form method="post" action="{{ $step('submit') }}">@csrf<button type="submit">{{ __('Submit for approval') }}</button></form>@endif
        @if($entry->status->notation === 'submitted')
            <form method="post" action="{{ $step('approve') }}" style="display: inline">@csrf<button type="submit">{{ __('Approve') }}</button></form>
            <form method="post" action="{{ $step('reject') }}" style="display: inline">@csrf <input name="reason" size="40" required aria-label="{{ __('Reason') }}" placeholder="{{ __('Reason') }}"><button type="submit">{{ __('Reject') }}</button></form>
        @endif
        @break
    @case('assessments')
        @if($entry->signed_off_at)<p>{{ __('Signed off by :name on :date', ['name' => $entry->signedOffBy?->name, 'date' => $entry->signed_off_at->format('Y-m-d')]) }}</p>
        @else<form method="post" action="{{ $step('sign-off') }}">@csrf<button type="submit">{{ __('Sign it off') }}</button></form>@endif
        @break
    @case('consent')
        @if($entry->withdrawn_at)<p>{{ __('Withdrawn on :date', ['date' => $entry->withdrawn_at->format('Y-m-d')]) }}</p>
        @else<form method="post" action="{{ $step('withdraw') }}">@csrf<button type="submit">{{ __('Record its withdrawal') }}</button></form>@endif
        @break
    @case('requests')
        @if($entry->verified_at)<p>{{ __('Identity verified by :name on :date', ['name' => $entry->verifiedBy?->name, 'date' => $entry->verified_at->format('Y-m-d')]) }}</p>
        @else<form method="post" action="{{ $step('verify') }}">@csrf<button type="submit">{{ __('The requester\'s identity is verified') }}</button></form>@endif
        @break
    @case('compromises')
        @php($notice = app(\App\Privacy\PrivacyService::class)->regulatorNotice($entry))
        <p>{{ $notice['told'] ? __('The Regulator has been told.') : __('The Regulator has not been told.') }}
            @if(! $notice['told'] && $entry->notification_required){{ $notice['deadline'] ? __('Due by :when.', ['when' => $notice['deadline']->format('Y-m-d H:i')]) : __('Due as soon as reasonably possible (POPIA s.22(2)).') }}@endif</p>
        @if($entry->notices->isNotEmpty())<ul>@foreach($entry->notices as $n)<li>{{ $n->recipient->label($lang) }}@if($n->recipient_name) ({{ $n->recipient_name }})@endif, {{ $n->method->label($lang) }}, {{ $n->sent_at->format('Y-m-d H:i') }}</li>@endforeach</ul>@endif
        <details><summary>{{ __('Record a notice') }}</summary>
            <form method="post" action="{{ $step('notify') }}">@csrf
                <p><label for="nr">{{ __('To') }}</label> @include('description._ric', ['term' => null]) <select id="nr" name="recipient">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'notification-recipient/')->get() as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    <label for="nm">{{ __('How') }}</label> @include('description._ric', ['term' => null]) <select id="nm" name="method">@foreach(\App\Models\Concept::choices(\App\Support\Vocab::BASE.'notification-method/')->get() as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    <label for="nn">{{ __('Name') }}</label> @include('description._ric', ['term' => null]) <input id="nn" name="recipient_name"> <label for="ns">{{ __('Sent') }}</label> @include('description._ric', ['term' => null]) <input id="ns" type="datetime-local" name="sent_at"></p>
                <p><label for="nc">{{ __('What it said') }}</label> @include('description._ric', ['term' => null])<br><textarea id="nc" name="content" rows="3" cols="70"></textarea></p>
                <button type="submit">{{ __('Record') }}</button></form></details>
        @break
@endswitch
