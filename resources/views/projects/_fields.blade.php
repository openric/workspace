{{-- OpenRiC Workspace - a research project's own fields (Heratio's createProject; roadmap phase 19f). Needs $p (or null), $types, $statuses, $visibilities. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@php($x = $p ? 'pe' : 'pn')
<label for="{{ $x }}-title">{{ __('Title') }}</label> <input id="{{ $x }}-title" name="title" required size="50" value="{{ old('title', $p?->title) }}"><br>
<label for="{{ $x }}-desc">{{ __('Description') }}</label><br><textarea id="{{ $x }}-desc" name="description" rows="3" cols="80">{{ old('description', $p?->description) }}</textarea><br>
<label for="{{ $x }}-type">{{ __('Kind') }}</label> <select id="{{ $x }}-type" name="project_type">@foreach($types as $c)<option value="{{ $c->notation }}" @selected(old('project_type', $p?->projectType->notation ?? 'personal') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">@foreach($statuses as $c)<option value="{{ $c->notation }}" @selected(old('status', $p?->status->notation ?? 'planning') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-vis">{{ __('Who may see it') }}</label> <select id="{{ $x }}-vis" name="visibility">@foreach($visibilities as $c)<option value="{{ $c->notation }}" @selected(old('visibility', $p?->visibility->notation ?? 'private') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>
<label for="{{ $x }}-aff">{{ __('Institution') }}</label> <input id="{{ $x }}-aff" name="affiliation" size="30" value="{{ old('affiliation', $p?->affiliation) }}">
<label for="{{ $x }}-sup">{{ __('Supervisor') }}</label> <input id="{{ $x }}-sup" name="supervisor" size="25" value="{{ old('supervisor', $p?->supervisor) }}"><br>
<label for="{{ $x }}-fund">{{ __('Funding source') }}</label> <input id="{{ $x }}-fund" name="funding_source" size="25" value="{{ old('funding_source', $p?->funding_source) }}">
<label for="{{ $x }}-grant">{{ __('Grant number') }}</label> <input id="{{ $x }}-grant" name="grant_number" size="15" value="{{ old('grant_number', $p?->grant_number) }}">
<label for="{{ $x }}-eth">{{ __('Ethics approval') }}</label> <input id="{{ $x }}-eth" name="ethics_approval" size="15" value="{{ old('ethics_approval', $p?->ethics_approval) }}"><br>
<label for="{{ $x }}-start">{{ __('Start date') }}</label> <input id="{{ $x }}-start" name="start_date" type="date" value="{{ old('start_date', $p?->start_date?->toDateString()) }}">
<label for="{{ $x }}-end">{{ __('Expected end date') }}</label> <input id="{{ $x }}-end" name="expected_end_date" type="date" value="{{ old('expected_end_date', $p?->expected_end_date?->toDateString()) }}">
<label for="{{ $x }}-actual">{{ __('Actual end date') }}</label> <input id="{{ $x }}-actual" name="actual_end_date" type="date" value="{{ old('actual_end_date', $p?->actual_end_date?->toDateString()) }}"><br>
