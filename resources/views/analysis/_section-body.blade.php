{{-- OpenRiC Workspace - what a report section holds (roadmap phase 19i). Needs $s, $service, $user, $report. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($type = $s->sectionType->notation)
@if($type === 'title-page')<div style="text-align: center; margin: 2rem 0"><h1>{{ $s->title ?? $report->title }}</h1><p>{{ $report->user->name }}</p><p>{{ now(config('workspace.timezone'))->format('j F Y') }}</p>@if($s->content)<p>{{ $s->content }}</p>@endif</div>
@elseif($type === 'toc')<h2>{{ $s->title ?? __('Contents') }}</h2><ol>@foreach($report->sections->filter(fn ($x) => in_array($x->sectionType->notation, ['heading', 'text', 'bibliography', 'collection-list', 'annotation-list', 'timeline'], true) && $x->title) as $x)<li>{{ $x->title }}</li>@endforeach</ol>
@elseif($type === 'heading')<h2>{{ $s->title }}</h2>@if($s->content)<p style="white-space: pre-wrap">{{ $s->content }}</p>@endif
@else
    @if($s->title)<h3>{{ $s->title }}</h3>@endif
    @if($s->content)<p style="white-space: pre-wrap">{{ $s->content }}</p>@endif
    @php($lines = $service->body($user, $s))
    @if($lines)<ul>@foreach($lines as $l)<li>{{ $l }}</li>@endforeach</ul>@elseif(in_array($type, ['bibliography', 'collection-list', 'annotation-list', 'timeline'], true))<p class="meta">{{ __('Nothing to list yet.') }}</p>@endif
@endif
