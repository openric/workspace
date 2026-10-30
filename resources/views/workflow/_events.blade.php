{{-- OpenRiC Workspace - workflow history, the latest first (Heratio's workflow history). Needs $events. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
@forelse($events as $e)
    <p style="margin: .2rem 0"><strong>{{ $e->performed_at->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</strong> {{ $e->action->label($lang) }}@if($e->step): {{ $e->step->name }}@endif
        ({{ $e->workflow->name }})@if($e->performedBy), {{ $e->performedBy->name }}@endif @if($e->comment)- {{ $e->comment }}@endif
        @if($e->task) <a href="{{ route('workflow.tasks.show', $e->task_id) }}">{{ __('task') }}</a>@endif</p>
@empty
    <p>{{ __('None.') }}</p>
@endforelse
