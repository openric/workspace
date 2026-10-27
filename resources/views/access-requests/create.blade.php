{{-- OpenRiC Workspace - asking for the files of an instantiation available on request (roadmap phase 7; after Heratio's ahg-access-request request-object). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Ask for access'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Ask for access') }}</h1>
    <p>{{ __('The files of :file, of :record, are available on request. Say why you need them; someone who reviews access requests will approve your request for a time, or refuse it with a reason. You will have an email either way.', [
        'file' => $instantiation->described('instantiation-title', [app()->getLocale(), 'en']), 'record' => $instantiation->record->described('title', [app()->getLocale(), 'en'])]) }}</p>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('access-requests.store', $instantiation) }}">
        @csrf
        <label for="reason">{{ __('Reason') }}</label>
        <small id="reason-help">{{ __('What you need the files for, such as the research they serve.') }}</small>
        <textarea id="reason" name="reason" rows="5" required maxlength="4000" aria-describedby="reason-help">{{ old('reason') }}</textarea>
        <label for="justification">{{ __('Justification (optional)') }}</label>
        <small id="justification-help">{{ __('Anything else the reviewer should know, such as a permission you hold.') }}</small>
        <textarea id="justification" name="justification" rows="3" maxlength="4000" aria-describedby="justification-help">{{ old('justification') }}</textarea>
        <label for="urgency">{{ __('Urgency') }}</label>
        <select id="urgency" name="urgency">
            @foreach($urgencies as $u)<option value="{{ $u->notation }}" @selected(old('urgency', 'normal') === $u->notation)>{{ $u->label($lang) }}: {{ $u->labels->firstWhere('language', 'en')?->definition }}</option>@endforeach
        </select>
        <button type="submit">{{ __('Send the request') }}</button>
    </form>
@endsection
