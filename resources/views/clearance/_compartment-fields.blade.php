{{-- OpenRiC Workspace - a compartment's fields (Heratio's security_compartment; roadmap phase 18c). Needs $c (or null), $x, $levels. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-code">{{ __('Code') }}</label> <input id="{{ $x }}-code" name="code" required size="14" value="{{ $c?->code }}">
<label for="{{ $x }}-name">{{ __('Name') }}</label> <input id="{{ $x }}-name" name="name" required size="30" value="{{ $c?->name }}">
<label for="{{ $x }}-min">{{ __('From the level') }}</label> <select id="{{ $x }}-min" name="min_level_id">@foreach($levels as $l)<option value="{{ $l->id }}" @selected($c?->min_level_id === $l->id)>{{ $l->level }} {{ $l->name }}</option>@endforeach</select><br>
<label for="{{ $x }}-desc">{{ __('Description') }}</label> <input id="{{ $x }}-desc" name="description" size="50" value="{{ $c?->description }}">
@foreach(['requires_need_to_know' => __('Need to know'), 'requires_briefing' => __('Briefing'), 'is_active' => __('In use')] as $f => $label)
    <input type="hidden" name="{{ $f }}" value="0"><label><input type="checkbox" name="{{ $f }}" value="1" @checked($c ? $c->{$f} : $f !== 'requires_briefing')> {{ $label }}</label>
@endforeach<br>
