{{-- OpenRiC Workspace - donor agreements: search and filter (Heratio's agreement dashboard). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Donor agreements'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Donor agreements') }} @include('description._ric', ['term' => 'rico:Record'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Deeds of gift, deposit and loan and the other agreements material comes under, with their rights, restrictions, reminders and documents. Agreements are for staff; the public never sees them.') }}</p>
    <p>@if(auth()->user()->may('create', 'agreement'))<a href="{{ route('agreements.create') }}">{{ __('New agreement') }}</a> · @endif
        <a href="{{ route('agreements.reminders') }}">{{ __('Reminders') }}</a></p>
    <form method="get" action="{{ route('agreements.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Agreement number or title') }}</label><input id="q" name="q" value="{{ $filters['q'] }}" maxlength="200"></div>
        @foreach(['status' => ['Status', $statuses], 'type' => ['Type', $types]] as $field => [$what, $list])
            <div><label for="{{ $field }}" style="margin-top: 0">{{ __($what) }}</label>
                <select id="{{ $field }}" name="{{ $field }}" style="font: inherit; padding: .5rem">
                    <option value="">{{ __('All') }}</option>
                    @foreach($list as $c)<option value="{{ $c->notation }}" @selected($filters[$field] === $c->notation)>{{ $c->label($lang) }}</option>@endforeach
                </select></div>
        @endforeach
        <div><label for="expiring" style="margin-top: 0">{{ __('Expiring') }}</label>
            <select id="expiring" name="expiring" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                @foreach(['7', '30', '90'] as $days)<option value="{{ $days }}" @selected($filters['expiring'] === $days)>{{ __('Within :days days', ['days' => $days]) }}</option>@endforeach
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Filter') }}</button>
    </form>
    @forelse($agreements as $agreement)
        <p><a href="{{ route('agreements.show', $agreement) }}">{{ $agreement->heading([app()->getLocale(), 'en']) }}</a>{{ collect([
            $donors[$agreement->id]->map(fn ($d) => $d->described('agent-authorised-name', [app()->getLocale(), 'en']))->join('; '),
            $agreement->type->label($lang), $agreement->status->label($lang),
            $agreement->agreed_on ? __('agreed :date', ['date' => $agreement->agreed_on->format('Y-m-d')]) : null,
            $agreement->expires_on ? __('expires :date', ['date' => $agreement->expires_on->format('Y-m-d')]) : null])->filter()->map(fn ($s) => ', '.$s)->join('') }}</p>
    @empty
        <p>{{ __(array_filter($filters) ? 'No agreement matches.' : 'No agreements yet.') }}</p>
    @endforelse
    @if($agreements->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($agreements->previousPageUrl())<a href="{{ $agreements->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $agreements->currentPage(), 'pages' => $agreements->lastPage()]) }}
            @if($agreements->nextPageUrl())<a href="{{ $agreements->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
