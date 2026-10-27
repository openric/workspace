{{-- OpenRiC Workspace - AccessRequestPendingMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@component('mail.access-request._layout', ['recipient' => $recipient, 'request' => $request])
<p>{{ __('A new request for access needs your review.') }}</p>
<p><strong>{{ __('File') }}:</strong> {{ $file }}<br>
<strong>{{ __('Requester') }}:</strong> {{ $request->user->name }}<br>
<strong>{{ __('Urgency') }}:</strong> {{ $request->urgency->label([app()->getLocale(), 'en']) }}<br>
<strong>{{ __('Received') }}:</strong> {{ $request->created_at->format('Y-m-d H:i') }}</p>
<p><strong>{{ __('Reason') }}:</strong></p>
<blockquote>{!! nl2br(e($request->reason)) !!}</blockquote>
@if($request->justification)<p><strong>{{ __('Justification') }}:</strong></p>
<blockquote>{!! nl2br(e($request->justification)) !!}</blockquote>@endif
@endcomponent
