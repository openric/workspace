{{-- OpenRiC Workspace - background jobs (roadmap phase 5; after Heratio's ahg-jobs browse). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Jobs'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Jobs') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ $all ? __('Work the Workspace does in the background for everyone in the institution: imports, and the viewing copies of uploaded files.') : __('Work the Workspace does in the background for you: imports, and the viewing copies of files you upload.') }}</p>
    @if($stats)
        <p>{{ __(':total jobs: :pending waiting, :running running, :completed completed, :failed failed, :cancelled cancelled.', $stats) }}</p>
        <p><a href="{{ route('jobs.csv') }}">{{ __('Export as CSV') }}</a></p>
        <form method="post" action="{{ route('jobs.clear-inactive') }}">@csrf<button type="submit" onclick="return confirm(@js(__('Clear the finished jobs older than 30 days?')))">{{ __('Clear finished jobs older than 30 days') }}</button></form>
    @endif
    <form method="get" action="{{ route('jobs.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="status" style="margin-top: 0">{{ __('Status') }}</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($filters['status'] === $s->notation)>{{ $s->label($lang) }}</option>@endforeach
            </select></div>
        <div><label for="type" style="margin-top: 0">{{ __('Type') }}</label>
            <select id="type" name="type" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($types as $t)<option value="{{ $t->notation }}" @selected($filters['type'] === $t->notation)>{{ $t->label($lang) }}</option>@endforeach
            </select></div>
        <div><label for="sort" style="margin-top: 0">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" style="font: inherit; padding: .5rem">
                <option value="date" @selected($filters['sort'] === 'date')>{{ __('Newest first') }}</option>
                <option value="name" @selected($filters['sort'] === 'name')>{{ __('Name') }}</option>
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    @if($jobs->isEmpty())
        <p>{{ __('No jobs.') }}</p>
    @else
        <ul>
            @foreach($jobs as $job)
                <li><a href="{{ route('jobs.show', $job) }}"><strong>{{ $job->name }}</strong></a>
                    <span class="meta">{{ collect([$job->type->label($lang), $job->status->label($lang), $all ? $job->user?->name : null, $job->created_at->format('Y-m-d H:i'),
                        $job->duration !== null ? trans_choice(':count second|:count seconds', $job->duration) : null])->filter(fn ($v) => $v !== null && $v !== '')->join(' · ') }}</span>
                    @if($job->error_message)<span class="summary">{{ \Illuminate\Support\Str::limit($job->error_message, 200) }}</span>@endif</li>
            @endforeach
        </ul>
        @if($jobs->hasPages())
            <nav aria-label="{{ __('Pages') }}"><p>
                @if($jobs->previousPageUrl())<a href="{{ $jobs->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
                {{ __('Page :page of :pages', ['page' => $jobs->currentPage(), 'pages' => $jobs->lastPage()]) }}
                @if($jobs->nextPageUrl())<a href="{{ $jobs->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
            </p></nav>
        @endif
    @endif
@endsection
