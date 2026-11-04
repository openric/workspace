{{-- OpenRiC Workspace - every DOI of the institution, by state (Heratio's DOI browse; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Every DOI'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Every DOI') }}</h1>
    @include('dois._nav')
    <form method="get" action="{{ route('dois.browse') }}"><label for="ds">{{ __('State') }}</label><select id="ds" name="state"><option value="">{{ __('Any') }}</option>@foreach($states as $s)<option value="{{ $s->notation }}" @selected($state === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select>
        <label for="dq">{{ __('DOI contains') }}</label><input id="dq" name="q" value="{{ $q }}"> <button type="submit">{{ __('Show') }}</button></form>
    @include('dois._list', ['dois' => $dois])
    {{ $dois->links() }}
@endsection
