{{-- OpenRiC Workspace - a background job (roadmap phase 5; after Heratio's ahg-jobs show). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $job->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @if($job->active())@push('head')<meta http-equiv="refresh" content="5">@endpush @endif
    <p><a href="{{ route('jobs.index') }}">{{ __('Jobs') }}</a></p>
    <h1>{{ $job->name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <table>
        <tbody>
            <tr><th scope="row">{{ __('Type') }}</th><td>{{ $job->type->label($lang) }}</td></tr>
            <tr><th scope="row">{{ __('Status') }}</th><td>{{ $job->status->label($lang) }}@if($job->active()) <small>{{ __('This page refreshes itself until it is done.') }}</small>@endif</td></tr>
            <tr><th scope="row">{{ __('Asked for by') }}</th><td>{{ $job->user?->name ?? __('The Workspace') }}</td></tr>
            <tr><th scope="row">{{ __('Created') }}</th><td>{{ $job->created_at->format('Y-m-d H:i:s') }}</td></tr>
            <tr><th scope="row">{{ __('Started') }}</th><td>{{ $job->started_at?->format('Y-m-d H:i:s') ?? '-' }}</td></tr>
            <tr><th scope="row">{{ __('Completed') }}</th><td>{{ $job->completed_at?->format('Y-m-d H:i:s') ?? '-' }}</td></tr>
            <tr><th scope="row">{{ __('Duration') }}</th><td>{{ $job->duration !== null ? trans_choice(':count second|:count seconds', $job->duration) : '-' }}</td></tr>
            @if($job->subject_table === 'imports')<tr><th scope="row">{{ __('Import') }}</th><td><a href="{{ route('imports.show', $job->subject_id) }}">{{ __('The import and its report') }}</a></td></tr>@endif
            @if($job->subject_table === 'instantiations' && ($instantiation = \App\Models\Instantiation::find($job->subject_id)))<tr><th scope="row">{{ __('Instantiation') }}</th><td><a href="{{ \App\Relations\EntityKinds::route($instantiation) }}">{{ \App\Relations\EntityKinds::label($instantiation) }}</a></td></tr>@endif
        </tbody>
    </table>
    @if($job->output)
        <h2>{{ __('Output') }}</h2>
        <table><tbody>@foreach($job->output as $key => $value)<tr><th scope="row">{{ $key }}</th><td>{{ is_scalar($value) ? var_export($value, true) : json_encode($value) }}</td></tr>@endforeach</tbody></table>
    @endif
    @if($job->error_message)
        <h2>{{ __('Error') }}</h2>
        <p>{{ $job->error_message }}</p>
        @if($all && $job->stack_trace)<details><summary>{{ __('Stack trace') }}</summary><pre style="white-space: pre-wrap">{{ $job->stack_trace }}</pre></details>@endif
    @endif
    @if($job->hasStatus('pending'))
        <form method="post" action="{{ route('jobs.cancel', $job) }}">@csrf<button type="submit">{{ __('Cancel') }}</button></form>
    @endif
@endsection
