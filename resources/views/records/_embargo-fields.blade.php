{{-- OpenRiC Workspace - the fields of an embargo (Heratio's embargo). Needs: $e (an Embargo or null), $p (a prefix for ids), $types, $reasons, $lang. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p><label for="{{ $p }}-type">{{ __('What it withholds') }}</label> <select id="{{ $p }}-type" name="type" required>
    @foreach($types as $t)<option value="{{ $t->notation }}" @selected(($e?->type->notation ?? old('type')) === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select></p>
<p><label for="{{ $p }}-reason">{{ __('Reason') }}</label> <select id="{{ $p }}-reason" name="reason"><option value="">-</option>
    @foreach($reasons as $r)<option value="{{ $r->notation }}" @selected(($e?->reason?->notation ?? old('reason')) === $r->notation)>{{ $r->label($lang) }}</option>@endforeach</select></p>
<p><label for="{{ $p }}-starts">{{ __('From') }}</label> <input id="{{ $p }}-starts" type="date" name="starts_on" value="{{ $e?->starts_on->format('Y-m-d') ?? old('starts_on') }}">
    <label for="{{ $p }}-ends">{{ __('until (empty: until lifted)') }}</label> <input id="{{ $p }}-ends" type="date" name="ends_on" value="{{ $e?->ends_on?->format('Y-m-d') ?? old('ends_on') }}">
    <label for="{{ $p }}-notify">{{ __('Warn this many days before it ends') }}</label> <input id="{{ $p }}-notify" type="number" min="1" max="3650" name="notify_days_before" value="{{ $e?->notify_days_before ?? old('notify_days_before', 30) }}" style="width: 5rem"></p>
<p><label for="{{ $p }}-note">{{ __('Note (staff only)') }}</label><br><textarea id="{{ $p }}-note" name="note" rows="2" cols="60">{{ $e?->note ?? old('note') }}</textarea></p>
<p><label for="{{ $p }}-public">{{ __('What the public catalogue says while it is in force') }}</label><br><input id="{{ $p }}-public" name="public_message" size="70" value="{{ $e?->public_message ?? old('public_message') }}"></p>
