{{-- OpenRiC Workspace - the frame of an access request mail (after Heratio's ahg-access-request emails). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
{{ $slot }}
<p><a href="{{ route('access-requests.show', $request) }}">{{ __('Open the request') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
