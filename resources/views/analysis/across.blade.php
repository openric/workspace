{{-- OpenRiC Workspace - a search across several fonds (Heratio's cross-fonds query; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Search across fonds'))
@section('content')
    <h1>{{ __('Search across fonds') }}</h1>
    @if($error)<p class="error" role="alert">{{ $error }}</p>@endif
    <form method="get" action="{{ route('research.across') }}">
        <label for="xf-q">{{ __('Words') }}</label> <input id="xf-q" name="q" size="40" value="{{ request('q') }}" required>
        <fieldset><legend>{{ __('Fonds') }}</legend>@foreach($fonds as $f)<label style="display: block"><input type="checkbox" name="fonds[]" value="{{ $f->id }}" @checked(in_array($f->id, $chosen, true))> {{ \App\Relations\EntityKinds::label($f, \App\Support\Vocab::languages()) }} <span class="meta">{{ $f->referenceCode() }}</span></label>@endforeach</fieldset>
        <button type="submit">{{ __('Search') }}</button></form>
    @if($results !== null)
        <h2>{{ trans_choice('{0} No result|{1} :count result|[2,*] :count results', count($results), ['count' => count($results)]) }}</h2>
        @foreach($results as $r)<p><a href="{{ route('records.show', $r['record']) }}">{{ $r['title'] }}</a> <span class="meta">{{ $r['record']->referenceCode() }} · {{ $r['fonds'] }}</span><br><small>{{ $r['snippet'] }}</small></p>@endforeach
    @endif
@endsection
