{{-- OpenRiC Workspace - the languages the collection is described in (roadmap phase 17d4), after Heratio's language corpus index (ahg-semantic-search). Needs $languages. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Languages of the collection'))
@section('content')
    <h1>{{ __('Languages of the collection') }}</h1>
    <p class="meta">{{ __($disclaimer) }}</p>
    @if($languages === [])<p>{{ __('No published description carries a language yet.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Language') }}</th><th scope="col">{{ __('Records described in it') }}</th><th scope="col">{{ __('Terms and places named in it') }}</th></tr></thead>
        <tbody>@foreach($languages as $l)<tr><td><a href="{{ route('discover.languages.show', $l['code']) }}" lang="{{ $l['code'] }}">{{ $l['label'] }}</a></td><td>{{ $l['records'] }}</td><td>{{ $l['terms'] }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
