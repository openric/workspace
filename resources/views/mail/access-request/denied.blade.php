{{-- OpenRiC Workspace - AccessRequestDeniedMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@component('mail.access-request._layout', ['recipient' => $recipient, 'request' => $request])
<p>{{ __('Your request for access to :file was refused.', ['file' => $file]) }}</p>
<p><strong>{{ __('Reason') }}:</strong></p>
<blockquote>{!! nl2br(e($request->review_notes)) !!}</blockquote>
@endcomponent
