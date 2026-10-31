{{-- OpenRiC Workspace - an entry of a privacy register: what it holds, its status and steps, and its form (roadmap phase 18a, ADR 0016). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $entry ? __($definition['one']).': '.$entry->getAttribute($definition['label']) : __('New :thing', ['thing' => mb_strtolower(__($definition['one']))]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('privacy.index') }}">{{ __('Privacy') }}</a> · <a href="{{ route('privacy.register', $register) }}">{{ __($definition['title']) }}</a></p>
    <h1>{{ $entry ? __($definition['one']).': '.$entry->getAttribute($definition['label']) : __('New :thing', ['thing' => mb_strtolower(__($definition['one']))]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if($entry)
        <dl>
            @isset($definition['status'])<dt>{{ __('Status') }}</dt><dd>{{ $entry->status->label($lang) }}</dd>@endisset
            @if($entry->due_on ?? null)<dt>{{ __('Due') }}</dt><dd>{{ $entry->due_on->format('Y-m-d') }}@if(! ($entry->completed_on ?? null) && $entry->due_on->isPast()) <strong>({{ __('overdue') }})</strong>@endif</dd>@endif
            @foreach($definition['fields'] as $field => $spec)@if($entry->getAttribute($field) !== null && $entry->getAttribute($field) !== '')<dt>{{ __($spec[1]) }}</dt><dd>@include('privacy._value', ['field' => $field, 'spec' => $spec])</dd>@endif @endforeach
        </dl>
        @include('privacy._steps')
        <details><summary>{{ __('Change it') }}</summary>
            <form method="post" action="{{ route('privacy.update', [$register, $entry->id]) }}">@csrf @foreach($definition['fields'] as $field => $spec)@include('privacy._input')@endforeach<button type="submit">{{ __('Save') }}</button></form></details>
    @else
        <form method="post" action="{{ route('privacy.store', $register) }}">@csrf @foreach($definition['fields'] as $field => $spec)@include('privacy._input')@endforeach<button type="submit">{{ __('Add') }}</button></form>
    @endif
@endsection
