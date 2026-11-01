{{-- OpenRiC Workspace - put one's own research in a project, or take it out (roadmap phase 19f). Needs $thing and $kind. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($choices = app(\App\Research\Projects::class)->contributing(auth()->user()))
@if($choices->isNotEmpty() || $thing->project_id)
    <form method="post" action="{{ route('research.projects.assign', [$kind, $thing->getKey()]) }}" class="no-print" style="display: inline">@csrf
        <label for="ps-{{ $kind }}-{{ $thing->getKey() }}">{{ __('Project') }}</label> <select id="ps-{{ $kind }}-{{ $thing->getKey() }}" name="project_id"><option value="">{{ __('None') }}</option>@foreach($choices as $p)<option value="{{ $p->id }}" @selected($thing->project_id === $p->id)>{{ $p->title }}</option>@endforeach</select>
        <button type="submit">{{ __('Set') }}</button></form>
@endif
