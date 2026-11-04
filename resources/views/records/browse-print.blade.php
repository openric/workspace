{{-- OpenRiC Workspace - the browse list ready to print, as filtered and sorted (Heratio's GLAM print view; roadmap phase 20d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Archival descriptions'))
@section('content')
    <p class="no-print"><a href="{{ request()->fullUrlWithQuery(['format' => null]) }}">{{ __('Back to the list') }}</a>
        <small>{{ __('Use your browser\'s print command; the menu is left out of the printout.') }}</small></p>
    <h1>{{ __('Archival descriptions') }}</h1>
    <p class="meta">{{ collect([$query !== '' ? __('Matching :q', ['q' => $query]) : null, trans_choice(':count unit|:count units', $units->count(), ['count' => $units->count()]), now()->translatedFormat('j F Y')])->filter()->join(' · ') }}</p>
    @php($lang = [app()->getLocale(), 'en'])
    <table><thead><tr><th scope="col">{{ __('Reference code') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Level') }}</th><th scope="col">{{ __('Dates') }}</th><th scope="col">{{ __('Extent') }}</th></tr></thead><tbody>
        @foreach($units as $unit)<tr><td>{{ $unit->referenceCode() }}</td><td>{{ $unit->described('title', $lang) }}</td><td>{{ $unit->level->label(\App\Support\Vocab::languages()) }}</td>
            <td>{{ $unit->described('dates-expressed', $lang) ?: $unit->dates->map->display()->filter()->join('; ') }}</td><td>{{ $unit->described('extent', $lang) }}</td></tr>@endforeach
    </tbody></table>
@endsection
