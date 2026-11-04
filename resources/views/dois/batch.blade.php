{{-- OpenRiC Workspace - mint DOIs for many published units at once (Heratio's batch mint; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Mint many'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Mint many') }}</h1>
    @include('dois._nav')
    @if(! $account)<p class="error">{{ __('DataCite is not set up yet.') }}</p>@endif
    <p>{{ __('Queues a DOI for each published unit that has none, of a repository or a level, or all, up to the number given. The queue sends them to DataCite a few at a time.') }}</p>
    <form method="post" action="{{ route('dois.batch') }}">@csrf
        <label for="br">{{ __('Repository') }}</label><select id="br" name="repository_id"><option value="">{{ __('Any') }}</option>@foreach($repositories as $r)<option value="{{ $r->id }}">{{ \App\Relations\EntityKinds::label($r) }}</option>@endforeach</select>
        <label for="bl">{{ __('Level of description') }}</label><select id="bl" name="level_id"><option value="">{{ __('Any') }}</option>@foreach($levels as $l)<option value="{{ $l->id }}">{{ $l->label($lang) }}</option>@endforeach</select>
        <label for="bn">{{ __('At most') }}</label><input id="bn" name="limit" type="number" min="1" max="5000" value="100">
        <button type="submit" @disabled(! $account)>{{ __('Queue them') }}</button></form>
@endsection
