{{-- OpenRiC Workspace - the publish gate rules, and whether publishing needs an approved workflow (Heratio's gate admin and workflow_required_for_publish). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Publish gates'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Publish gates') }}</h1>
    @include('workflow._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('What a description must have before it is published. A blocker refuses publishing; a warning lets it go ahead with a warning. Someone who manages workflows may publish anyway, which the audit log records.') }}</p>
    <form method="post" action="{{ route('workflow.publishing') }}">@csrf
        <input type="hidden" name="workflow_required_for_publish" value="0">
        <label style="font-weight: normal"><input type="checkbox" name="workflow_required_for_publish" value="1" @checked($institution->workflow_required_for_publish) style="width: auto"> {{ __('A description is published only once a workflow for it is approved') }}</label>
        <button type="submit">{{ __('Save') }}</button></form>
    <h2>{{ __('Rules') }}</h2>
    @foreach($rules as $r)
        <details><summary><strong>{{ $r->name }}</strong> ({{ collect([$r->ruleType->label($lang), $r->severity->label($lang), $r->field_name, $r->level?->label($lang), $r->repository?->described('authorised-name', $lang), $r->is_active ? null : __('off')])->filter()->join(', ') }})</summary>
            <form method="post" action="{{ route('workflow.gates.save', $r) }}">@csrf @include('workflow._gate-fields', ['r' => $r, 'p' => 'g'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('workflow.gates.destroy', $r) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form>
        </details>
    @endforeach
    <details><summary>{{ __('Add a rule') }}</summary>
        <form method="post" action="{{ route('workflow.gates.save') }}">@csrf @include('workflow._gate-fields', ['r' => null, 'p' => 'g-new'])<button type="submit">{{ __('Add') }}</button></form></details>
@endsection
