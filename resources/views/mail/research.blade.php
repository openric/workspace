{{-- OpenRiC Workspace - ResearchMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
@foreach($lines as [$line, $replace])<p>{{ __($line, $replace) }}</p>@endforeach
@if($link)<p><a href="{{ $link }}">{{ __($linkText ?? 'Open it') }}</a></p>@endif
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
