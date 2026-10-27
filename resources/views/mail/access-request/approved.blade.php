{{-- OpenRiC Workspace - AccessRequestApprovedMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@component('mail.access-request._layout', ['recipient' => $recipient, 'request' => $request])
<p>{{ __('Your request for access to :file was approved. You may open its files until :date.', ['file' => $file, 'date' => $request->expires_at->format('Y-m-d H:i')]) }}</p>
@if($request->review_notes)<p><strong>{{ __('Reviewer\'s note') }}:</strong></p>
<blockquote>{!! nl2br(e($request->review_notes)) !!}</blockquote>@endif
@endcomponent
