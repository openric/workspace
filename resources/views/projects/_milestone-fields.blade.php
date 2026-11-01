{{-- OpenRiC Workspace - a milestone's fields (Heratio's research_milestone; roadmap phase 19f). Needs $m (or null), $x, $milestoneTypes, $milestoneStatuses. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<label for="{{ $x }}-title">{{ __('Title') }}</label> <input id="{{ $x }}-title" name="title" required size="40" value="{{ $m?->title }}">
<label for="{{ $x }}-type">{{ __('Kind') }}</label> <select id="{{ $x }}-type" name="milestone_type">@foreach($milestoneTypes as $c)<option value="{{ $c->notation }}" @selected(($m?->milestoneType->notation ?? 'milestone') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
<label for="{{ $x }}-status">{{ __('Status') }}</label> <select id="{{ $x }}-status" name="status">@foreach($milestoneStatuses as $c)<option value="{{ $c->notation }}" @selected(($m?->status->notation ?? 'planned') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>
<label for="{{ $x }}-due">{{ __('Due') }}</label> <input id="{{ $x }}-due" name="due_date" type="date" value="{{ $m?->due_date?->toDateString() }}">
<label for="{{ $x }}-done">{{ __('Completed on') }}</label> <input id="{{ $x }}-done" name="completed_date" type="date" value="{{ $m?->completed_date?->toDateString() }}">
<label for="{{ $x }}-pct">{{ __('Progress (%)') }}</label> <input id="{{ $x }}-pct" name="progress_pct" type="number" min="0" max="100" value="{{ $m?->progress_pct ?? 0 }}" style="width: 5rem">
<label for="{{ $x }}-order">{{ __('Order') }}</label> <input id="{{ $x }}-order" name="sort_order" type="number" min="0" value="{{ $m?->sort_order ?? 0 }}" style="width: 5rem"><br>
<label for="{{ $x }}-deliv">{{ __('Deliverable') }}</label> <input id="{{ $x }}-deliv" name="deliverable" size="50" value="{{ $m?->deliverable }}"><br>
<label for="{{ $x }}-desc">{{ __('Description') }}</label><br><textarea id="{{ $x }}-desc" name="description" rows="2" cols="70">{{ $m?->description }}</textarea><br>
