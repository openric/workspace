{{-- OpenRiC Workspace - the fields of a migration pathway (roadmap phase 15c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-from">{{ __('From the PRONOM format') }}</label><input id="{{ $x }}-from" name="source_puid" value="{{ $p?->source_puid }}" required placeholder="fmt/40">
<label for="{{ $x }}-to">{{ __('Into the PRONOM format') }}</label><input id="{{ $x }}-to" name="target_puid" value="{{ $p?->target_puid }}" required placeholder="fmt/354">
<label for="{{ $x }}-tool">{{ __('Tool') }}</label><select id="{{ $x }}-tool" name="tool">@foreach($choices['conversion-tool'] as $c)<option value="{{ $c->notation }}" @selected($p?->tool_id === $c->id)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-ext">{{ __('With the extension') }}</label><input id="{{ $x }}-ext" name="target_ext" value="{{ $p?->target_ext }}" required pattern="[a-z0-9]{1,8}" placeholder="pdf">
<label for="{{ $x }}-tmime">{{ __('And the MIME type') }}</label><input id="{{ $x }}-tmime" name="target_mime" value="{{ $p?->target_mime }}" required placeholder="application/pdf">
<label for="{{ $x }}-quality">{{ __('What it loses') }}</label><select id="{{ $x }}-quality" name="quality_impact">@foreach($choices['migration-quality-impact'] as $c)<option value="{{ $c->notation }}" @selected($p?->quality_impact_id === $c->id || ($p === null && $c->notation === 'minimal'))>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-fidelity">{{ __('Fidelity, from 0 to 100') }}</label><input id="{{ $x }}-fidelity" name="fidelity_score" type="number" min="0" max="100" value="{{ $p?->fidelity_score }}">
<label for="{{ $x }}-priority">{{ __('Priority (lower first)') }}</label><input id="{{ $x }}-priority" name="priority" type="number" value="{{ $p?->priority ?? 100 }}">
<label for="{{ $x }}-options">{{ __('Options, as JSON') }}</label><input id="{{ $x }}-options" name="options" value="{{ $p?->options ? json_encode($p->options) : '' }}">
<label for="{{ $x }}-notes">{{ __('Notes') }}</label><textarea id="{{ $x }}-notes" name="notes" rows="2">{{ $p?->notes }}</textarea>
<label><input type="checkbox" name="is_recommended" value="1" @checked($p?->is_recommended)> {{ __('Recommended for its source format') }}</label>
<label><input type="checkbox" name="is_automated" value="1" @checked($p?->is_automated)> {{ __('Safe to run without review') }}</label>
