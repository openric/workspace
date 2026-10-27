{{-- OpenRiC Workspace - access requests to review (roadmap phase 7; after Heratio's pending and browse). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Access requests'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Access requests') }}</h1>
    <p>{{ __('Requests for files available on request, for the records you may review requests for.') }}</p>
    <form method="get" action="{{ route('access-requests.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="status" style="margin-top: 0">{{ __('Status') }}</label>
            <select id="status" name="status" style="font: inherit; padding: .5rem"><option value="all" @selected($status === null)>{{ __('Any') }}</option>
                @foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($status === $s->notation)>{{ $s->label($lang) }}</option>@endforeach
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    @if($requests->isEmpty())
        <p>{{ __('No requests.') }}</p>
    @else
        <ul>@foreach($requests as $r)@include('access-requests._row', ['r' => $r, 'withRequester' => true])@endforeach</ul>
        @if($requests->hasPages())
            <nav aria-label="{{ __('Pages') }}"><p>
                @if($requests->previousPageUrl())<a href="{{ $requests->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
                {{ __('Page :page of :pages', ['page' => $requests->currentPage(), 'pages' => $requests->lastPage()]) }}
                @if($requests->nextPageUrl())<a href="{{ $requests->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
            </p></nav>
        @endif
    @endif
@endsection
