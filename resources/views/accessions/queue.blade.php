{{-- OpenRiC Workspace - the intake queue: its statistics, and the accessions in it filtered and sorted (PSIS's accessionIntake queue, Heratio's intake queue and accession dashboard). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Intake queue'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('accessions.index') }}">{{ __('Accessions') }}</a></p>
    <h1>{{ __('Intake queue') }}</h1>
    <p>{{ __(':total accessions, :recent added in the last 30 days. :overdue submitted or under review for more than seven days.', ['total' => $stats['total'], 'recent' => $stats['recent'], 'overdue' => $stats['overdue']]) }}
        @if($stats['hours_to_accept'] !== null){{ __('From submitted to accepted takes :hours hours on average.', ['hours' => $stats['hours_to_accept']]) }}@endif</p>
    <p><strong>{{ __('Intake status') }}:</strong> {{ $statuses->map(fn ($s) => $s->label($lang).' '.($stats['status'][$s->notation] ?? 0))->join(', ') }}
        <br><strong>{{ __('Priority') }}:</strong> {{ $priorities->map(fn ($p) => $p->label($lang).' '.($stats['priority'][$p->notation] ?? 0))->join(', ') }}
        <br><strong>{{ __('Processing status') }}:</strong> {{ $processing->map(fn ($r) => ($r->label ?? __('Not set')).' '.$r->n)->join(', ') ?: __('none') }}</p>
    <form method="get" action="{{ route('accessions.queue') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Accession number or title') }}</label><input id="q" name="q" value="{{ $filters['q'] }}" maxlength="200"></div>
        <div><label for="status" style="margin-top: 0">{{ __('Intake status') }}</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem"><option value="">{{ __('Open (not accepted or rejected)') }}</option>@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($filters['status'] === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select></div>
        <div><label for="priority" style="margin-top: 0">{{ __('Priority') }}</label>
            <select id="priority" name="priority" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>@foreach($priorities as $p)<option value="{{ $p->notation }}" @selected($filters['priority'] === $p->notation)>{{ $p->label($lang) }}</option>@endforeach</select></div>
        <div><label for="assigned" style="margin-top: 0">{{ __('Assigned to') }}</label>
            <select id="assigned" name="assigned" style="font: inherit; padding: .5rem"><option value="">{{ __('Anyone') }}</option><option value="me" @selected($filters['assigned'] === 'me')>{{ __('Me') }}</option><option value="none" @selected($filters['assigned'] === 'none')>{{ __('Nobody') }}</option></select></div>
        <div><label for="from" style="margin-top: 0">{{ __('Added from') }}</label><input id="from" name="from" value="{{ $filters['from'] }}" placeholder="YYYY-MM-DD" maxlength="10"></div>
        <div><label for="to" style="margin-top: 0">{{ __('Added to') }}</label><input id="to" name="to" value="{{ $filters['to'] }}" placeholder="YYYY-MM-DD" maxlength="10"></div>
        <div><label for="sort" style="margin-top: 0">{{ __('Sort by') }}</label>
            <select id="sort" name="sort" style="font: inherit; padding: .5rem">@foreach(['created_at' => 'Newest first', 'submitted_at' => 'Latest submitted', 'number' => 'Accession number'] as $value => $what)<option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ __($what) }}</option>@endforeach</select></div>
        <button type="submit" style="margin-top: 0">{{ __('Filter') }}</button>
    </form>
    @forelse($accessions as $accession)
        <p><a href="{{ route('accessions.show', $accession) }}#intake">{{ $accession->heading([app()->getLocale(), 'en']) }}</a>{{ collect([$accession->intakeStatus->label($lang), $accession->intakePriority->label($lang),
            $accession->assignedTo?->name, $accession->submitted_at ? __('submitted :date', ['date' => $accession->submitted_at->format('Y-m-d')]) : null,
            __('added :date', ['date' => $accession->created_at->format('Y-m-d')])])->filter()->map(fn ($s) => ', '.$s)->join('') }}</p>
    @empty
        <p>{{ __('No accession in the queue matches.') }}</p>
    @endforelse
    @if($accessions->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($accessions->previousPageUrl())<a href="{{ $accessions->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $accessions->currentPage(), 'pages' => $accessions->lastPage()]) }}
            @if($accessions->nextPageUrl())<a href="{{ $accessions->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
