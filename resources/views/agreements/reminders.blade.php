{{-- OpenRiC Workspace - the reminders of every donor agreement, the next due first (Heratio's agreement reminders). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Agreement reminders'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('agreements.index') }}">{{ __('Donor agreements') }}</a></p>
    <h1>{{ __('Agreement reminders') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="get" action="{{ route('agreements.reminders') }}" style="display: flex; gap: .75rem; align-items: end">
        <div><label for="status" style="margin-top: 0">{{ __('Status') }}</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem">
                <option value="open" @selected($status === 'open')>{{ __('Active or snoozed') }}</option>
                <option value="" @selected($status === '')>{{ __('All') }}</option>
                @foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($status === $s->notation)>{{ $s->label($lang) }}</option>@endforeach
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Filter') }}</button>
    </form>
    @forelse($reminders as $r)
        <p style="margin: .6rem 0 .2rem"><strong>{{ $r->remind_on->format('Y-m-d') }}</strong> {{ $r->subject }}:
            <a href="{{ route('agreements.show', $r->agreement) }}#reminders">{{ $r->agreement->heading([app()->getLocale(), 'en']) }}</a>{{ collect([$r->type->label($lang), $r->priority->label($lang), $r->status->label($lang),
                $r->agreement->expires_on ? __('expires :date', ['date' => $r->agreement->expires_on->format('Y-m-d')]) : null])->filter()->map(fn ($s) => ', '.$s)->join('') }}</p>
        @if(auth()->user()->may('update', 'agreement', $r->agreement))@include('agreements._reminder-actions', ['reminder' => $r, 'back' => 'reminders'])@endif
    @empty
        <p>{{ __('No reminders.') }}</p>
    @endforelse
    @if($reminders->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($reminders->previousPageUrl())<a href="{{ $reminders->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $reminders->currentPage(), 'pages' => $reminders->lastPage()]) }}
            @if($reminders->nextPageUrl())<a href="{{ $reminders->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
