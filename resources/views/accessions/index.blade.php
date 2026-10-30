{{-- OpenRiC Workspace - accessions: search and filter (Heratio's accession browse). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Accessions'))
@section('content')
    <h1>{{ __('Accessions') }} @include('description._ric', ['term' => 'rico:Activity'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('What the institution received, from whom and when, before and while it is described. Accession records are for staff; the public never sees them.') }}</p>
    <p>@if(auth()->user()->may('create', 'accession'))<a href="{{ route('accessions.create') }}">{{ __('Add new accession record') }}</a> · @endif
        <a href="{{ route('accessions.queue') }}">{{ __('Intake queue') }}</a> · <a href="{{ route('accessions.donors') }}">{{ __('Donors') }}</a> · <a href="{{ route('accessions.valuation-report') }}">{{ __('Valuation report') }}</a>@if(auth()->user()->may('manage-institution', 'institution')) · <a href="{{ route('accessions.settings') }}">{{ __('Accession settings') }}</a>@endif · <a href="{{ route('accessions.csv') }}">{{ __('Export CSV') }}</a> · <a href="{{ route('accessions.caais-all') }}">{{ __('Export CAAIS') }}</a></p>
    <form method="get" action="{{ route('accessions.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Accession number or title') }}</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="status" style="margin-top: 0">{{ __('Processing status') }}</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                @foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($status === $s->notation)>{{ $s->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Search') }}</button>
    </form>
    @forelse($accessions as $accession)
        <p><a href="{{ route('accessions.show', $accession) }}">{{ $accession->heading([app()->getLocale(), 'en']) }}</a>{{ collect([$accession->dates->first(fn ($d) => $d->property->notation === 'acquisition')?->display(), $accession->processingStatus?->label(\App\Support\Vocab::languages()), $accession->processingPriority?->label(\App\Support\Vocab::languages())])->filter()->map(fn ($s) => ', '.$s)->join('') }}</p>
    @empty
        <p>{{ __($query !== '' || $status !== '' ? 'No accession matches.' : 'No accessions yet.') }}</p>
    @endforelse
    @if($accessions->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($accessions->previousPageUrl())<a href="{{ $accessions->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $accessions->currentPage(), 'pages' => $accessions->lastPage()]) }}
            @if($accessions->nextPageUrl())<a href="{{ $accessions->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
