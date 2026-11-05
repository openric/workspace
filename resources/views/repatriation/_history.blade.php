{{-- OpenRiC Workspace - a repatriation claim's status history, newest first (roadmap phase 17d2). Needs $claim with statusChanges. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@forelse($claim->statusChanges as $change)
    <p>{{ $change->created_at->format('Y-m-d H:i') }} · @if($change->from){{ $change->from->label($lang) }} → @endif<strong>{{ $change->to->label($lang) }}</strong>@if($change->changed_by_name) · {{ $change->changed_by_name }}@endif
        @if($change->note)<br><small>{{ $change->note }}</small>@endif</p>
@empty<p>{{ __('No status changes recorded yet.') }}</p>@endforelse
