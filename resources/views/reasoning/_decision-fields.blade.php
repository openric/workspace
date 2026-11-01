{{-- OpenRiC Workspace - a decision's fields (Heratio's research_decision_log; roadmap phase 19i). Needs $d (or null), $x, $types. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-sum">{{ __('What was decided') }}</label> <input id="{{ $x }}-sum" name="summary" required size="60" value="{{ $d?->summary }}">
<label for="{{ $x }}-type">{{ __('Kind') }}</label> <select id="{{ $x }}-type" name="decision_type">@foreach($types as $t)<option value="{{ $t->notation }}" @selected(($d?->decisionType->notation ?? 'other') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select><br>
<label for="{{ $x }}-reason">{{ __('Why') }}</label><br><textarea id="{{ $x }}-reason" name="reason" rows="2" cols="80">{{ $d?->reason }}</textarea><br>
<label for="{{ $x }}-by">{{ __('Decided by') }}</label> <input id="{{ $x }}-by" name="decided_by" size="25" value="{{ $d?->decided_by ?? auth()->user()->name }}">
<label for="{{ $x }}-at">{{ __('On') }}</label> <input id="{{ $x }}-at" name="decided_at" type="date" value="{{ $d?->decided_at?->timezone(config('workspace.timezone'))->toDateString() ?? now(config('workspace.timezone'))->toDateString() }}">
<label for="{{ $x }}-ref">{{ __('About') }}</label> <input id="{{ $x }}-ref" name="related_ref" size="30" value="{{ $d?->related_ref }}"><br>
