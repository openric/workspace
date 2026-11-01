{{-- OpenRiC Workspace - research journals (Heratio's research journals; roadmap phase 19g). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research journals'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Research journals') }}</h1>
    <p><a href="{{ route('research.journals.directory') }}">{{ __('The directory of journals to aim at') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @forelse($journals as $j)<p><a href="{{ route('research.journals.show', $j) }}">{{ $j->title }}</a> · {{ $j->kind->label($lang) }} · {{ $j->status->label($lang) }} · <span class="meta">{{ $j->user?->name ?? __('the institution') }}</span></p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    <details><summary>{{ __('New journal or manuscript') }}</summary><form method="post" action="{{ route('research.journals.store') }}">@csrf @include('journals._fields', ['j' => null])
        @if($run)<label><input type="checkbox" name="institutional" value="1"> {{ __('A journal of the institution') }}</label><br>@endif<button type="submit">{{ __('Create') }}</button></form></details>
@endsection
