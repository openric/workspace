{{-- OpenRiC Workspace - asking for access to a classified record above one's clearance (Heratio's security access request; roadmap phase 18c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Access to a classified record'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Access to a classified record') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('clearance')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p>{{ __('Name the record by its persistent IRI, as you were given it. Who administers the institution decides, for a time.') }}</p>
    <form method="post" action="{{ route('clearance.request.submit') }}">@csrf
        <label for="r-iri">{{ __('Persistent IRI') }}</label> <input id="r-iri" name="iri" required size="60" value="{{ old('iri') }}"><br>
        <label for="r-type">{{ __('To') }}</label> <select id="r-type" name="request_type">@foreach($types as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select>
        <label for="r-priority">{{ __('Priority') }}</label> <select id="r-priority" name="priority">@foreach($priorities as $p)<option value="{{ $p->notation }}">{{ $p->label($lang) }}</option>@endforeach</select><br>
        <label for="r-why">{{ __('Why you need it') }}</label><br><textarea id="r-why" name="justification" rows="3" cols="70" required>{{ old('justification') }}</textarea><br>
        <button type="submit">{{ __('Ask') }}</button></form>
    @if($mine->isNotEmpty())<h2>{{ __('Your requests') }}</h2><ul>@foreach($mine as $m)<li>{{ $m->record->iri }} · {{ $m->requestType->label($lang) }} · {{ $m->status->label($lang) }}</li>@endforeach</ul>@endif
@endsection
