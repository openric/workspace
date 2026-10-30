{{-- OpenRiC Workspace - the workflow's own links. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p><a href="{{ route('workflow.dashboard') }}">{{ __('Workflow') }}</a> · <a href="{{ route('workflow.mine') }}">{{ __('My tasks') }}</a> · <a href="{{ route('workflow.pool') }}">{{ __('Pool') }}</a>
    · <a href="{{ route('workflow.team') }}">{{ __('Team work') }}</a> · <a href="{{ route('workflow.history') }}">{{ __('History') }}</a>
    @if(app(\App\Description\WorkflowService::class)->manages(auth()->user())) · <a href="{{ route('workflow.admin') }}">{{ __('Workflows') }}</a>@endif</p>
