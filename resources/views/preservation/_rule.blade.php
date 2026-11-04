{{-- OpenRiC Workspace - the fields of a normalisation rule (roadmap phase 15c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-puid">{{ __('PRONOM format (PUID)') }}</label><input id="{{ $x }}-puid" name="source_puid" value="{{ $r?->source_puid }}" placeholder="fmt/11">
<label for="{{ $x }}-mime">{{ __('or MIME type') }}</label><input id="{{ $x }}-mime" name="source_mime" value="{{ $r?->source_mime }}" placeholder="image/png">
<label for="{{ $x }}-purpose">{{ __('Purpose') }}</label><select id="{{ $x }}-purpose" name="purpose">@foreach($choices['normalisation-purpose'] as $c)<option value="{{ $c->notation }}" @selected($r?->purpose_id === $c->id)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-format">{{ __('Into the format') }}</label><input id="{{ $x }}-format" name="target_format" value="{{ $r?->target_format }}" required placeholder="TIFF">
<label for="{{ $x }}-ext">{{ __('With the extension') }}</label><input id="{{ $x }}-ext" name="target_ext" value="{{ $r?->target_ext }}" required pattern="[a-z0-9]{1,8}" placeholder="tiff">
<label for="{{ $x }}-tmime">{{ __('And the MIME type') }}</label><input id="{{ $x }}-tmime" name="target_mime" value="{{ $r?->target_mime }}" required placeholder="image/tiff">
<label for="{{ $x }}-tool">{{ __('Tool') }}</label><select id="{{ $x }}-tool" name="tool">@foreach($choices['conversion-tool'] as $c)<option value="{{ $c->notation }}" @selected($r?->tool_id === $c->id)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-priority">{{ __('Priority (lower first)') }}</label><input id="{{ $x }}-priority" name="priority" type="number" value="{{ $r?->priority ?? 100 }}">
<label for="{{ $x }}-options">{{ __('Options, as JSON') }}</label><input id="{{ $x }}-options" name="options" value="{{ $r?->options ? json_encode($r->options) : '' }}" placeholder='{"quality": 95, "compress": "lzw"}'>
<label><input type="checkbox" name="is_active" value="1" @checked($r?->is_active ?? true)> {{ __('Active') }}</label>
