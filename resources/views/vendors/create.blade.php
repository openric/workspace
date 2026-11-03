{{-- OpenRiC Workspace - making an authority record a vendor (Heratio's ahg-vendor add). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Add a vendor'))
@section('content')
    <h1>{{ __('Add a vendor') }}</h1>
    @include('vendors._nav')
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A vendor is an authority record: its names, contact points and relations are kept there. Choose the record, or make a new corporate body.') }}</p>
    @unless($agent)
        <form method="get" action="{{ route('vendors.create') }}"><label for="q">{{ __('Find the authority record by name or identifier') }}</label><input id="q" name="q" value="{{ $q }}" maxlength="200"> <button type="submit">{{ __('Find') }}</button></form>
    @endunless
    <form method="post" action="{{ route('vendors.store') }}">
        @csrf
        @if($agent)
            <input type="hidden" name="agent" value="{{ $agent->id }}"><p><strong>{{ \App\Relations\EntityKinds::label($agent) }}</strong> @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($agent)])</p>
        @else
            <fieldset><legend>{{ __('Authority record') }}</legend>
                @foreach($candidates as $c)<label><input type="radio" name="agent" value="{{ $c->id }}" @checked($loop->first)> {{ \App\Relations\EntityKinds::label($c) }}</label>@endforeach
                <label><input type="radio" name="agent" value="" @checked($candidates->isEmpty())> {{ __('A new corporate body, named:') }}</label>
                <input name="new_name" value="{{ old('new_name') }}" maxlength="500" aria-label="{{ __('Name of the new corporate body') }}"></fieldset>
        @endif
        @include('vendors._form', ['vendor' => null])
        <button type="submit">{{ __('Make it a vendor') }}</button>
    </form>
@endsection
