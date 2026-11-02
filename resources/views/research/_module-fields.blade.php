{{-- OpenRiC Workspace - a training module's fields (roadmap phase 19j). Needs $m (or null), $x, $lectures. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-title">{{ __('Title') }}</label> <input id="{{ $x }}-title" name="title" required size="40" value="{{ $m?->title }}">
<label for="{{ $x }}-lecture">{{ __('Lecture') }}</label> <select id="{{ $x }}-lecture" name="lecture_id"><option value="">{{ __('None') }}</option>@foreach($lectures as $l)<option value="{{ $l->id }}" @selected($m?->lecture_id === $l->id)>{{ $l->title }}</option>@endforeach</select>
<label for="{{ $x }}-order">{{ __('Order') }}</label> <input id="{{ $x }}-order" name="sort_order" type="number" style="width: 5rem" value="{{ $m?->sort_order }}"><br>
<label for="{{ $x }}-body">{{ __('Text (Markdown)') }}</label><br><textarea id="{{ $x }}-body" name="body" rows="4" cols="80">{{ $m?->body }}</textarea><br>
