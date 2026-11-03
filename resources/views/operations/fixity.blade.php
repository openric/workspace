{{-- OpenRiC Workspace - fixity schedules and runs (Heratio's ahg-integrity schedules and runs; roadmap phase 12a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Fixity'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Fixity') }}</h1>
    <p><a href="{{ route('operations.index') }}">{{ __('Operations') }}</a> · <a href="{{ route('fixity.failures') }}">{{ __('Failures') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Each schedule rehashes the files it covers and compares each with the size and SHA-256 it came in with, the longest unchecked first, within its limits of files and time; what it does not reach waits for the next run. A file that is changed or missing stays open under Failures until it passes again or someone closes it with a reason. Every check is kept, as a PREMIS fixity check event.') }}</p>
    @foreach($schedules as $s)
        <details><summary>{{ $s->name }} · {{ $s->frequency->label($lang) }} · {{ $s->scope->label($lang) }}@if($s->repository): {{ \App\Relations\EntityKinds::label($s->repository) }}@endif @if($s->record): {{ \App\Relations\EntityKinds::label($s->record) }}@endif
            @unless($s->is_enabled) · <strong>{{ __('off') }}</strong>@endunless @if($s->next_run_at) · {{ __('next :date', ['date' => $s->next_run_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) }}@endif</summary>
            <form method="post" action="{{ route('fixity.schedules.update', $s) }}">@csrf @include('operations._schedule', ['s' => $s, 'x' => 's'.$loop->index]) <button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('fixity.schedules.run', $s) }}">@csrf<button type="submit">{{ __('Run it now') }}</button></form>
        </details>
    @endforeach
    <details @if($schedules->isEmpty()) open @endif><summary>{{ __('Add a schedule') }}</summary><form method="post" action="{{ route('fixity.schedules.store') }}">@csrf @include('operations._schedule', ['s' => null, 'x' => 'new']) <button type="submit">{{ __('Add') }}</button></form></details>
    <h2>{{ __('Runs') }}</h2>
    @include('operations._runs', ['runs' => $runs])
@endsection
