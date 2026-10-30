{{-- OpenRiC Workspace - add or change a workflow and its steps (Heratio's create and edit workflow). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $workflow ? $workflow->name : __('Add a workflow'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('workflow._nav')
    <h1>{{ $workflow ? $workflow->name : __('Add a workflow') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ $workflow ? route('workflow.admin.update', $workflow) : route('workflow.admin.store') }}">
        @csrf
        <label for="name">{{ __('Name') }}</label><input id="name" name="name" required maxlength="255" value="{{ old('name', $workflow?->name) }}">
        <label for="description">{{ __('Description') }}</label><textarea id="description" name="description" rows="2" style="width: 100%; font: inherit">{{ old('description', $workflow?->description) }}</textarea>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 0 1rem">
            <div><label for="scope">{{ __('Scope') }}</label><select id="scope" name="scope" style="font: inherit; padding: .5rem">@foreach($scopes as $c)<option value="{{ $c->notation }}" @selected(old('scope', $workflow?->scope->notation ?? 'global') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select></div>
            <div><label for="repository">{{ __('Repository (for a repository scope)') }}</label><select id="repository" name="repository" style="font: inherit; padding: .5rem"><option value="">-</option>@foreach($repositories as $r)<option value="{{ $r->id }}" @selected(old('repository', $workflow?->scope_repository_id) === $r->id)>{{ $r->described('authorised-name', $lang) }}</option>@endforeach</select></div>
            <div><label for="record">{{ __('Collection (for a collection scope)') }}</label><select id="record" name="record" style="font: inherit; padding: .5rem"><option value="">-</option>@foreach($collections as $r)<option value="{{ $r->id }}" @selected(old('record', $workflow?->scope_record_id) === $r->id)>{{ $r->described('title', $lang) }}</option>@endforeach</select></div>
            <div><label for="trigger">{{ __('Trigger') }}</label><select id="trigger" name="trigger" style="font: inherit; padding: .5rem">@foreach($triggers as $c)<option value="{{ $c->notation }}" @selected(old('trigger', $workflow?->trigger->notation ?? 'manual') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select></div>
            <div><label for="applies_to">{{ __('Applies to') }}</label><select id="applies_to" name="applies_to" style="font: inherit; padding: .5rem">
                @foreach(['record' => 'Archival descriptions', 'agent' => 'Authority records', 'repository' => 'Repositories', 'accession' => 'Accessions'] as $kind => $text)<option value="{{ $kind }}" @selected(old('applies_to', $workflow?->applies_to ?? 'record') === $kind)>{{ __($text) }}</option>@endforeach</select></div>
            <div><label for="auto_archive_days">{{ __('Archive completed tasks after (days)') }}</label><input id="auto_archive_days" name="auto_archive_days" inputmode="numeric" value="{{ old('auto_archive_days', $workflow?->auto_archive_days) }}"></div>
        </div>
        @foreach(['is_active' => ['Active', true], 'is_default' => ['The default for its kind (a global scope)', false], 'require_all_steps' => ['Every step, and every checklist item, must be done in order', true],
            'allow_parallel' => ['Steps may run in parallel', false], 'notification_enabled' => ['Send notifications', true]] as $flag => [$text, $default])
            <input type="hidden" name="{{ $flag }}" value="0">
            <label style="font-weight: normal"><input type="checkbox" name="{{ $flag }}" value="1" @checked(old($flag, $workflow ? $workflow->{$flag} : $default)) style="width: auto"> {{ __($text) }}</label>
        @endforeach
        <button type="submit">{{ __('Save') }}</button>
    </form>

    @if($workflow)
        <section id="steps">
            <h2>{{ __('Steps') }}</h2>
            @forelse($workflow->steps as $step)
                <div style="border-top: 1px solid var(--line); padding: .4rem 0">
                    <p style="margin: .2rem 0"><strong>{{ $step->position }}. {{ $step->name }}</strong> ({{ collect([$step->stepType->label($lang), $step->action->label($lang), $step->requiredRole?->name($lang),
                        $step->autoAssignUser ? __('to :name', ['name' => $step->autoAssignUser->name]) : ($step->pool_enabled ? __('from the pool') : __('assigned')),
                        $step->escalation_days ? trans_choice('due in :count day|due in :count days', $step->escalation_days, ['count' => $step->escalation_days]) : null,
                        $step->is_optional ? __('optional') : null, $step->is_active ? null : __('inactive')])->filter()->join(', ') }})</p>
                    <div style="display: flex; gap: .4rem; flex-wrap: wrap">
                        @foreach(['earlier' => 'Move up', 'later' => 'Move down'] as $direction => $text)
                            @if(($direction === 'earlier' && ! $loop->parent->first) || ($direction === 'later' && ! $loop->parent->last))
                                <form method="post" action="{{ route('workflow.admin.steps.move', [$workflow, $step]) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __($text) }}</button></form>
                            @endif
                        @endforeach
                        <form method="post" action="{{ route('workflow.admin.steps.destroy', [$workflow, $step]) }}">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>
                    </div>
                    <details><summary>{{ __('Edit') }}</summary>
                        <form method="post" action="{{ route('workflow.admin.steps.save', [$workflow, $step]) }}">@csrf
                            @include('workflow._step-fields', ['s' => $step, 'p' => 'st'.$loop->index])
                            <button type="submit">{{ __('Save') }}</button></form>
                    </details>
                </div>
            @empty
                <p>{{ __('No steps yet.') }}</p>
            @endforelse
            <details><summary>{{ __('Add a step') }}</summary>
                <form method="post" action="{{ route('workflow.admin.steps.save', $workflow) }}">@csrf
                    @include('workflow._step-fields', ['s' => null, 'p' => 'st-new'])
                    <button type="submit">{{ __('Add') }}</button></form>
            </details>
        </section>
        <form method="post" action="{{ route('workflow.admin.destroy', $workflow) }}" style="margin-top: 2rem">@csrf<button type="submit">{{ __('Delete the workflow') }}</button></form>
    @endif
@endsection
