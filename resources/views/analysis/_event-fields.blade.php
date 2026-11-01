{{-- OpenRiC Workspace - a timeline event's fields (roadmap phase 19i). Needs $e (or null), $x, $types. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-label">{{ __('Label') }}</label> <input id="{{ $x }}-label" name="label" required size="40" value="{{ $e?->label }}">
<label for="{{ $x }}-type">{{ __('Kind') }}</label> <select id="{{ $x }}-type" name="date_type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected(($e?->dateType->notation ?? 'event') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select><br>
<label for="{{ $x }}-from">{{ __('From') }}</label> <input id="{{ $x }}-from" name="date_start" type="date" required value="{{ $e?->date_start?->toDateString() }}">
<label for="{{ $x }}-to">{{ __('To') }}</label> <input id="{{ $x }}-to" name="date_end" type="date" value="{{ $e?->date_end?->toDateString() }}">
<label for="{{ $x }}-colour">{{ __('Colour') }}</label> <input id="{{ $x }}-colour" name="color" type="color" value="{{ $e?->color ?? '#10373e' }}"><br>
<label for="{{ $x }}-desc">{{ __('Description') }}</label> <input id="{{ $x }}-desc" name="description" size="60" value="{{ $e?->description }}"><br>
