{{-- OpenRiC Workspace - a lecture section's fields (roadmap phase 19j). Needs $s (or null), $x, $mediaTypes. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-heading">{{ __('Heading') }}</label> <input id="{{ $x }}-heading" name="heading" size="40" value="{{ $s?->heading }}">
<label for="{{ $x }}-order">{{ __('Order') }}</label> <input id="{{ $x }}-order" name="sort_order" type="number" style="width: 5rem" value="{{ $s?->sort_order }}"><br>
<label for="{{ $x }}-media">{{ __('Media web address') }}</label> <input id="{{ $x }}-media" name="media_url" type="url" size="40" value="{{ $s?->media_url }}">
<label for="{{ $x }}-mtype">{{ __('Media kind') }}</label> <select id="{{ $x }}-mtype" name="media_type"><option value="">{{ __('None') }}</option>@foreach($mediaTypes as $c)<option value="{{ $c->notation }}" @selected($s?->mediaType?->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>
<label for="{{ $x }}-body">{{ __('Text (Markdown)') }}</label><br><textarea id="{{ $x }}-body" name="body" rows="6" cols="80">{{ $s?->body }}</textarea><br>
