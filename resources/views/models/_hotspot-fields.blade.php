{{-- OpenRiC Workspace - a hotspot's kind, colour and link (roadmap phase 21). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($types = \App\Models\Concept::choices(\App\Files\ModelViews::HOTSPOT_TYPES)->get())
<label for="{{ $prefix }}-type">{{ __('Kind') }} @include('description._ric', ['term' => 'oa:Annotation'])</label>
<select id="{{ $prefix }}-type" name="type">@foreach($types as $type)<option value="{{ $type->notation }}" @selected(($hotspot?->type?->notation ?? old('type', 'annotation')) === $type->notation)>{{ $type->label($lang) }}</option>@endforeach</select>
<label for="{{ $prefix }}-color">{{ __('Colour') }}</label><input id="{{ $prefix }}-color" name="color" type="color" value="{{ $hotspot?->color ?? '#10373E' }}">
<label for="{{ $prefix }}-link">{{ __('Link (optional)') }}</label><input id="{{ $prefix }}-link" name="link_url" type="url" value="{{ $hotspot?->link_url }}" maxlength="2000">
