{{-- OpenRiC Workspace - AgreementReminderMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($agreement = $reminder->agreement)
@php($lang = [app()->getLocale(), 'en'])
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
<p>{{ __('A reminder for the agreement :agreement is due on :date.', ['agreement' => $agreement->heading($lang), 'date' => $due]) }}</p>
<p><strong>{{ $reminder->subject }}</strong><br>
    {{ __('Type') }}: {{ $reminder->type->label($lang) }}. {{ __('Priority') }}: {{ $reminder->priority->label($lang) }}.
    @if($agreement->expires_on)<br>{{ __('Expiry date') }}: {{ $agreement->expires_on->format('Y-m-d') }}.@endif</p>
@if($reminder->description)<p style="white-space: pre-line">{{ $reminder->description }}</p>@endif
@if($reminder->action_required)<p><strong>{{ __('Action required') }}:</strong> {{ $reminder->action_required }}</p>@endif
<p><a href="{{ route('agreements.show', $agreement) }}">{{ __('Open the agreement') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
