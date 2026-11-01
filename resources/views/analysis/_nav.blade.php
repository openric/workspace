{{-- OpenRiC Workspace - the analysis pages of a research project (roadmap phase 19i). Needs $project. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p class="no-print"><a href="{{ route('research.projects.show', $project) }}">{{ $project->title }}</a> ·
    @foreach(['research.analysis.snapshots' => 'Snapshots', 'research.analysis.timeline' => 'Timeline', 'research.analysis.map' => 'Map', 'research.analysis.alerts' => 'Field alerts'] as $r => $l)
        @if(request()->routeIs($r))<strong>{{ __($l) }}</strong>@else<a href="{{ route($r, $project) }}">{{ __($l) }}</a>@endif @if(! $loop->last)·@endif
    @endforeach</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
@if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
