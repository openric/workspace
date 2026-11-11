{{-- OpenRiC Workspace - an entry's field as read (roadmap phase 18a). Needs: $entry, $field, $spec, $lang. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($v = $entry->getAttribute($field))
@php($rel = \Illuminate\Support\Str::camel(substr($field, 0, -3)))
@switch($spec[0] ?? 'text')
    @case('bool'){{ $v ? __('Yes') : __('No') }}@break
    @case('vocab'){{ $entry->{$rel}?->label($lang) }}@break
    @case('jurisdiction'){{ $entry->jurisdiction?->name }}@break
    @case('user'){{ $entry->{$rel}?->name }}@break
    @case('officer'){{ $entry->officer?->name }}@break
    @case('processing'){{ $entry->processingActivity?->name }}@break
    @case('date'){{ $v?->format('Y-m-d') }}@break
    @case('datetime'){{ $v?->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}@break
    @default<span style="white-space: pre-line">{{ $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v }}</span>
@endswitch
