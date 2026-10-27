{{-- OpenRiC Workspace - repositories list. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Repositories'))
@section('content')
    <h1>{{ __('Repositories') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if(auth()->user()->may('create', 'repository'))<p><a href="{{ route('repositories.create') }}">{{ __('New repository') }}</a> · <a href="{{ route('imports.create') }}">{{ __('Import') }}</a></p>@endif
    @php($lang = [app()->getLocale(), 'en'])
    @if($repositories->isEmpty())
        <p>{{ __('No repositories yet.') }}</p>
    @else
        <ul>
            @foreach($repositories as $repository)
                @php($contact = $repository->contactPoints->firstWhere('is_primary', true) ?? $repository->contactPoints->first())
                <li><a href="{{ route('repositories.show', $repository) }}"><strong>{{ $repository->described('authorised-name', $lang) }}</strong></a>
                    @include('description._row-actions', ['entity' => $repository])
                    <span class="meta">{{ collect([$repository->identifier, $contact ? collect([$contact->city, $contact->region, $contact->country_code])->filter()->join(', ') : null,
                        trans_choice(':count archival description|:count archival descriptions', $repository->records_count), $repository->descriptionStatus?->label(\App\Support\Vocab::languages())])->filter()->join(' · ') }}</span>
                    @if($contact && ($contact->email || $contact->telephone || $contact->website))<span class="meta">{{ collect([$contact->email, $contact->telephone, $contact->website])->filter()->join(' · ') }}</span>@endif
                    @if($about = $repository->described('holdings', $lang) ?: $repository->described('history', $lang))<span class="summary">{{ \Illuminate\Support\Str::limit(strip_tags($about), 260) }}</span>@endif</li>
            @endforeach
        </ul>
    @endif
@endsection
