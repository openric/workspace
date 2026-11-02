{{-- OpenRiC Workspace - a funding call's fields (Heratio's research_grant_call; roadmap phase 19h). Needs $c (or null), $x, $statuses, $projects, $lang. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label for="{{ $x }}-title">{{ __('Title') }}</label> <input id="{{ $x }}-title" name="title" required size="40" value="{{ $c?->title }}">
<label for="{{ $x }}-funder">{{ __('Funder') }}</label> <input id="{{ $x }}-funder" name="funder" required size="30" value="{{ $c?->funder }}"><br>
<label for="{{ $x }}-url">{{ __('Web address') }}</label> <input id="{{ $x }}-url" name="url" type="url" size="40" value="{{ $c?->url }}">
<label for="{{ $x }}-deadline">{{ __('Closes on') }}</label> <input id="{{ $x }}-deadline" name="deadline" type="date" value="{{ $c?->deadline?->toDateString() }}">
<label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected(($c?->status->notation ?? 'watching') === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-project">{{ __('Project') }}</label> <select id="{{ $x }}-project" name="project_id"><option value="">{{ __('None yet') }}</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected($c?->project_id === $p->id)>{{ $p->title }}</option>@endforeach</select><br>
<label for="{{ $x }}-notes">{{ __('Notes') }}</label><br><textarea id="{{ $x }}-notes" name="notes" rows="2" cols="70">{{ $c?->notes }}</textarea><br>
