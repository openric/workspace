{{-- OpenRiC Workspace - the studios of a research project (roadmap phase 19g). Needs $project. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p class="no-print"><a href="{{ route('research.projects.show', $project) }}">{{ $project->title }}</a> ·
    @foreach(['research.studios.question' => 'Question builder', 'research.studios.method' => 'Method studio', 'research.studios.triage' => 'Source triage', 'research.studios.argument' => 'Argument builder', 'research.studios.writing' => 'Writing studio', 'research.studios.review' => 'Review studio', 'research.studios.publication' => 'Publication studio', 'research.studios.time-machine' => 'Time machine'] as $r => $l)
        @if(request()->routeIs($r))<strong>{{ __($l) }}</strong>@else<a href="{{ route($r, $project) }}">{{ __($l) }}</a>@endif @if(! $loop->last)·@endif
    @endforeach</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
@if($errors->any() && ! $errors->has('research'))<p class="error" role="alert">{{ $errors->first() }}</p>@endif
