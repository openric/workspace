{{-- OpenRiC Workspace - a researcher's own page (roadmap phase 19b, ADR 0017), after Heratio's researcher registration, profile and dashboard (ahg-research). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Researcher'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Researcher') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($errors->any() && ! $errors->has('research'))<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if(! $researcher || $researcher->status->notation === 'rejected')
        @if($researcher)<p class="error">{{ __('Your registration was not approved: :reason', ['reason' => $researcher->rejection_reason]) }} {{ __('You may correct it and register again.') }}</p>@endif
        <p>{{ __('Register as a researcher to book the reading room and ask for records to be ready when you come.') }}</p>
        <form method="post" action="{{ route('research.register') }}" enctype="multipart/form-data">@csrf @include('research._fields', ['r' => $researcher])<button type="submit">{{ __('Register') }}</button></form>
    @else
        <p><strong>{{ $researcher->status->label($lang) }}</strong>@if($researcher->type) · {{ $researcher->type->name }}@endif
            @if($researcher->expires_on) · {{ __('until :date', ['date' => $researcher->expires_on->format('j F Y')]) }}@endif
            @if($researcher->card_number) · {{ __('reader\'s card :card', ['card' => $researcher->card_number]) }}@endif</p>
        @if($researcher->status->notation === 'pending')<p>{{ __('The reading room will review your registration.') }}</p>@endif
        @if($researcher->status->notation === 'suspended')<p class="error">{{ __('Your registration is suspended: :reason', ['reason' => $researcher->rejection_reason]) }}</p>@endif
        @if(in_array($researcher->status->notation, ['expired', 'approved'], true) && ($researcher->status->notation === 'expired' || $researcher->expires_on?->diffInDays(now()) <= \App\Research\Researchers::REMIND_DAYS))
            <form method="post" action="{{ route('research.renew') }}">@csrf<button type="submit">{{ __('Ask to renew my registration') }}</button></form>
        @endif
        @if($active)<p><a href="{{ route('research.book') }}">{{ __('Book the reading room') }}</a></p>@endif
        <h2>{{ __('My bookings') }}</h2>
        @if($bookings->isEmpty())<p>{{ __('None.') }}</p>@else
        <table><thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Time') }}</th><th scope="col">{{ __('Reading room') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>@foreach($bookings as $b)<tr><td><a href="{{ route('research.bookings.show', $b) }}">{{ $b->booking_date->format('Y-m-d') }}</a></td><td>{{ substr($b->start_time, 0, 5) }} - {{ substr($b->end_time, 0, 5) }}</td><td>{{ $b->room->name }}</td><td>{{ $b->status->label($lang) }}</td></tr>@endforeach</tbody></table>
        @endif
        <details><summary>{{ __('My details') }}</summary>
            <form method="post" action="{{ route('research.profile.update') }}" enctype="multipart/form-data">@csrf @include('research._fields', ['r' => $researcher])<button type="submit">{{ __('Save') }}</button></form>
        </details>
        <h2>{{ __('ORCID') }}</h2>
        @if($orcidLink)
            <p>{{ __('Linked to :orcid by signing in at ORCID on :date.', ['orcid' => $orcidLink->orcid, 'date' => $orcidLink->linked_at->format('j F Y')]) }} <a href="https://orcid.org/{{ $orcidLink->orcid }}" rel="noopener noreferrer">https://orcid.org/{{ $orcidLink->orcid }}</a></p>
            <form method="post" action="{{ route('research.orcid.step', 'pull') }}" style="display: inline">@csrf<button type="submit">{{ __('Read my works from ORCID') }}</button></form>
            <form method="post" action="{{ route('research.orcid.step', 'unlink') }}" style="display: inline">@csrf<button type="submit">{{ __('Unlink') }}</button></form>
            @if($orcidLink->works)<p class="meta">{{ __('Works on ORCID, read :date', ['date' => $orcidLink->works_pulled_at?->format('j F Y')]) }}</p>
                <ul>@foreach($orcidLink->works as $w)<li>{{ $w['title'] }}@if($w['year']) ({{ $w['year'] }})@endif @if($w['doi']) <a href="https://doi.org/{{ $w['doi'] }}" rel="noopener noreferrer">{{ $w['doi'] }}</a>@endif</li>@endforeach</ul>@endif
        @elseif($orcidReady)
            <form method="post" action="{{ route('research.orcid.step', 'link') }}">@csrf<button type="submit">{{ __('Link my ORCID iD by signing in at ORCID') }}</button></form>
        @else
            <p>{{ __('ORCID is not set up yet. Ask the institution to set it up under the research settings.') }}</p>
        @endif
        <p><a href="{{ route('research.calls') }}">{{ __('Funding calls you watch') }}</a></p>
    @endif
@endsection
