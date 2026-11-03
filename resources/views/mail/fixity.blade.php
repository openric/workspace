{{-- OpenRiC Workspace - FixityMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
<p>{{ __('The fixity run of :schedule checked :checked files: :changed changed and :missing missing. A changed or missing file is no longer what was received; restore it from a backup.', ['schedule' => $run->schedule?->name, 'checked' => $run->files_checked, 'changed' => $run->files_changed, 'missing' => $run->files_missing]) }}</p>
<p><a href="{{ route('fixity.failures') }}">{{ __('Open the failures') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
