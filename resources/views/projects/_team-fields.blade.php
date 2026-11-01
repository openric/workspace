{{-- OpenRiC Workspace - a team member's fields (Heratio's research_team_member; roadmap phase 19f). Needs $m (or null), $x, $teamRoles, $teamStatuses. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-name">{{ __('Name') }}</label> <input id="{{ $x }}-name" name="person_name" required size="30" value="{{ $m?->person_name }}">
<label for="{{ $x }}-role">{{ __('Role') }}</label> <select id="{{ $x }}-role" name="role">@foreach($teamRoles as $c)<option value="{{ $c->notation }}" @selected(($m?->role->notation ?? 'researcher') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">@foreach($teamStatuses as $c)<option value="{{ $c->notation }}" @selected(($m?->status->notation ?? 'active') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
<label><input type="checkbox" name="is_lead" value="1" @checked($m?->is_lead)> {{ __('Leads the project') }}</label><br>
<label for="{{ $x }}-aff">{{ __('Institution') }}</label> <input id="{{ $x }}-aff" name="affiliation" size="30" value="{{ $m?->affiliation }}">
<label for="{{ $x }}-email">{{ __('E-mail address') }}</label> <input id="{{ $x }}-email" name="email" type="email" size="25" value="{{ $m?->email }}">
<label for="{{ $x }}-orcid">ORCID iD</label> <input id="{{ $x }}-orcid" name="orcid" size="20" placeholder="0000-0000-0000-0000" value="{{ $m?->orcid }}"><br>
<label for="{{ $x }}-from">{{ __('From') }}</label> <input id="{{ $x }}-from" name="start_date" type="date" value="{{ $m?->start_date?->toDateString() }}">
<label for="{{ $x }}-to">{{ __('To') }}</label> <input id="{{ $x }}-to" name="end_date" type="date" value="{{ $m?->end_date?->toDateString() }}"><br>
<label for="{{ $x }}-contrib">{{ __('Contribution') }}</label><br><textarea id="{{ $x }}-contrib" name="contribution_note" rows="2" cols="70">{{ $m?->contribution_note }}</textarea><br>
