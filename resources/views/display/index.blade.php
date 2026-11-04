{{-- OpenRiC Workspace - display profiles and browse settings (Heratio's ahg-display; roadmap phase 20d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Display'))
@section('content')
    <h1>{{ __('Display') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <h2>{{ __('Display profiles') }}</h2>
    <p>{{ __('How the public catalogue shows a unit of description: which elements, in what order and under what label, with the hierarchy beside it or not, its pictures at what size, and what a reader may do from the page. A profile is for one level of description, or the default for every level without one. With no profile, a page shows every element it has.') }}</p>
    <p><a href="{{ route('display.create') }}">{{ __('New profile') }}</a></p>
    <table><thead><tr><th scope="col">{{ __('Profile') }}</th><th scope="col">{{ __('Level') }}</th><th scope="col">{{ __('Elements') }}</th><th scope="col">{{ __('Actions offered') }}</th></tr></thead>
        <tbody>@forelse($profiles as $p)<tr><td><a href="{{ route('display.edit', $p) }}">{{ $p->name }}</a></td><td>{{ $p->level?->label(\App\Support\Vocab::languages()) ?? __('Every level (default)') }}</td>
            <td>{{ $p->elements === [] ? __('All') : count($p->elements) }}</td><td>{{ $p->actions->map(fn ($a) => $a->label(\App\Support\Vocab::languages()))->join(', ') ?: __('None') }}</td></tr>
        @empty<tr><td colspan="4">{{ __('No profiles yet: every page shows every element it has.') }}</td></tr>@endforelse</tbody></table>

    <h2>{{ __('How staff browse') }}</h2>
    <p>{{ __('How the list of archival descriptions is shown to the institution\'s staff, unless an account sets its own.') }}</p>
    <form method="post" action="{{ route('display.browse') }}">@csrf
        <label for="b-view">{{ __('Show as') }}</label><select id="b-view" name="view">@foreach($views as $o)<option value="{{ $o->notation }}" @selected($browse['view'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
        <label for="b-sort">{{ __('Sort by') }}</label><select id="b-sort" name="sort">@foreach($sorts as $o)<option value="{{ $o->notation }}" @selected($browse['sort'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
        <label for="b-direction">{{ __('Direction') }}</label><select id="b-direction" name="direction">@foreach($directions as $o)<option value="{{ $o->notation }}" @selected($browse['direction'] === $o->notation)>{{ $o->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select>
        <label for="b-per-page">{{ __('Units a page') }}</label><input id="b-per-page" name="per_page" type="number" min="10" max="200" value="{{ $browse['per_page'] }}">
        <label><input type="hidden" name="show_thumbnails" value="0"><input type="checkbox" name="show_thumbnails" value="1" style="width: auto" @checked($browse['show_thumbnails'])> {{ __('Show images in the table and the cards') }}</label>
        <label><input type="hidden" name="allow_user_override" value="0"><input type="checkbox" name="allow_user_override" value="1" style="width: auto" @checked($browse['may_override'])> {{ __('Each account may set its own') }}</label>
        <button type="submit">{{ __('Save') }}</button></form>
@endsection
