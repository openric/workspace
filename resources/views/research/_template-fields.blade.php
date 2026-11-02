{{-- OpenRiC Workspace - a research document template's fields (roadmap phase 19j). Needs $t (or null), $x, $types, $fieldLines. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-name">{{ __('Name') }}</label> <input id="{{ $x }}-name" name="name" required size="40" value="{{ $t?->name }}">
<label for="{{ $x }}-type">{{ __('Kind') }}</label> @include('projects._select', ['id' => $x.'-type', 'name' => 'document_type', 'choices' => $types, 'current' => $t?->documentType->notation, 'default' => 'other'])<br>
<label for="{{ $x }}-desc">{{ __('Description') }}</label> <input id="{{ $x }}-desc" name="description" size="60" value="{{ $t?->description }}"><br>
<label for="{{ $x }}-fields">{{ __('Fields, one a line: label | text, textarea or date | required') }}</label><br><textarea id="{{ $x }}-fields" name="fields" rows="5" cols="70" required>{{ $fieldLines }}</textarea><br>
