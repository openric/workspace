{{-- OpenRiC Workspace - add an entry to the clipboard, or take it off (Heratio's clipboard toggle). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $type (records, agents or repositories) and $id. --}}
@php($on = app(\App\Clipboard\ClipboardService::class)->has($type, $id))
<form method="post" action="{{ $on ? route('clipboard.remove') : route('clipboard.add') }}" class="no-print" style="display: inline">
    @csrf
    <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
    <button type="submit" aria-pressed="{{ $on ? 'true' : 'false' }}" style="margin: .25rem 0; padding: .3rem .8rem; font-size: .85rem">{{ $on ? 'On the clipboard: remove' : 'Add to clipboard' }}</button>
</form>
