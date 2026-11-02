{{-- OpenRiC Workspace - a classification level's fields (Heratio's security_classification; roadmap phase 18c). Needs $l (or null), $x. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-code">{{ __('Code') }}</label> <input id="{{ $x }}-code" name="code" required size="14" value="{{ $l?->code }}">
<label for="{{ $x }}-level">{{ __('Level') }}</label> <input id="{{ $x }}-level" name="level" type="number" min="0" max="100" required style="width: 5rem" value="{{ $l?->level }}">
<label for="{{ $x }}-name">{{ __('Name') }}</label> <input id="{{ $x }}-name" name="name" required size="25" value="{{ $l?->name }}">
<label for="{{ $x }}-colour">{{ __('Colour') }}</label> <input id="{{ $x }}-colour" name="colour" type="color" value="{{ $l?->colour ?? '#666666' }}"><br>
<label for="{{ $x }}-desc">{{ __('Description') }}</label> <input id="{{ $x }}-desc" name="description" size="50" value="{{ $l?->description }}">
<label for="{{ $x }}-hours">{{ __('Longest session (hours)') }}</label> <input id="{{ $x }}-hours" name="max_session_hours" type="number" min="1" style="width: 5rem" value="{{ $l?->max_session_hours }}"><br>
@foreach(['requires_justification' => __('Classifying needs a justification'), 'requires_approval' => __('Access needs approval'), 'requires_2fa' => __('Needs two-factor sign-in (phase 12)'), 'watermark_required' => __('Watermark'), 'download_allowed' => __('Downloads allowed'), 'print_allowed' => __('Printing allowed'), 'copy_allowed' => __('Copying allowed'), 'is_active' => __('In use')] as $f => $label)
    <input type="hidden" name="{{ $f }}" value="0"><label><input type="checkbox" name="{{ $f }}" value="1" @checked($l ? $l->{$f} : in_array($f, ['download_allowed', 'print_allowed', 'copy_allowed', 'is_active'], true))> {{ $label }}</label>
@endforeach<br>
