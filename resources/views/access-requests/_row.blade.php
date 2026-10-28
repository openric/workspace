{{-- OpenRiC Workspace - one access request in a list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<li><a href="{{ route('access-requests.show', $r) }}"><strong>{{ $r->subject ?? \Illuminate\Support\Str::ucfirst($r->target()) }}</strong></a>
    <span class="meta">{{ collect([$r->instantiation ? __('of :record', ['record' => $r->record->described('title', [app()->getLocale(), 'en'])]) : ($r->subject ? \Illuminate\Support\Str::ucfirst($r->target()) : null), $withRequester ? $r->user->name : null,
        $r->status->label($lang), $r->urgency->notation !== 'normal' ? $r->urgency->label($lang) : null, $r->created_at->format('Y-m-d H:i'),
        $r->grantsAccess() ? __('access until :date', ['date' => $r->expires_at->format('Y-m-d')]) : null])->filter()->join(' · ') }}</span>
    <span class="summary">{{ \Illuminate\Support\Str::limit($r->reason, 200) }}</span></li>
