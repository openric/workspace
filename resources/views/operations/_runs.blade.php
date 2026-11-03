{{-- OpenRiC Workspace - a table of fixity runs. Needs $runs. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($tz = config('workspace.timezone'))
<table><thead><tr><th scope="col">{{ __('Began') }}</th><th scope="col">{{ __('Schedule') }}</th><th scope="col">{{ __('Started by') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Checked') }}</th><th scope="col">{{ __('Changed') }}</th><th scope="col">{{ __('Missing') }}</th></tr></thead>
    <tbody>@forelse($runs as $r)<tr><td><a href="{{ route('fixity.run', $r) }}">{{ $r->started_at->timezone($tz)->format('Y-m-d H:i') }}</a></td><td>{{ $r->schedule?->name ?? __('Every file') }}</td><td>{{ $r->trigger->label($lang) }}</td>
        <td>{{ $r->status->label($lang) }}</td><td>{{ number_format($r->files_checked) }}</td><td>@if($r->files_changed)<strong>{{ $r->files_changed }}</strong>@else 0 @endif</td><td>@if($r->files_missing)<strong>{{ $r->files_missing }}</strong>@else 0 @endif</td></tr>
    @empty<tr><td colspan="7">{{ __('No runs yet.') }}</td></tr>@endforelse</tbody></table>
