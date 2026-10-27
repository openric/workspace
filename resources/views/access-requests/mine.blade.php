{{-- OpenRiC Workspace - an account's access requests (roadmap phase 7; after Heratio's my-requests). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('My access requests'))
@section('content')
    <h1>{{ __('My access requests') }}</h1>
    <p>{{ __('Your requests for files available on request. To ask for one, open the instantiation and choose Ask for access.') }}</p>
    @if($requests->isEmpty())
        <p>{{ __('No requests.') }}</p>
    @else
        <ul>@foreach($requests as $r)@include('access-requests._row', ['r' => $r, 'withRequester' => false])@endforeach</ul>
        @if($requests->hasPages())
            <nav aria-label="{{ __('Pages') }}"><p>
                @if($requests->previousPageUrl())<a href="{{ $requests->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
                {{ __('Page :page of :pages', ['page' => $requests->currentPage(), 'pages' => $requests->lastPage()]) }}
                @if($requests->nextPageUrl())<a href="{{ $requests->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
            </p></nav>
        @endif
    @endif
@endsection
