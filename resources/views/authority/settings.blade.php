{{-- OpenRiC Workspace - resolving names: candidates, words of role and outside authorities (Heratio's authority resolution settings; roadmap phase 17b2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Resolving names'))
@section('content')
    <h1>{{ __('Resolving names') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('authority.settings.save') }}">@csrf
        <label for="as-top">{{ __('Candidates a name is given') }}</label><input id="as-top" name="top_n" type="number" min="1" max="50" value="{{ $topN }}">
        <h2>{{ __('Words of role') }}</h2>
        <p class="meta">{{ __('Words near a name that say what part its bearer played, one a line, looked for within 120 characters of the name and compared with a candidate\'s history.') }}</p>
        @foreach($kinds as $k)
            <label for="as-k-{{ $k->notation }}">{{ $k->label(\App\Support\Vocab::languages()) }}</label>
            <textarea id="as-k-{{ $k->notation }}" name="tokens[{{ $k->notation }}]" rows="4">{{ implode("\n", $tokens[$k->notation] ?? []) }}</textarea>
        @endforeach
        <h2>{{ __('Outside authorities') }}</h2>
        <p class="meta">{{ __('Searched to fill a new authority record or place, the first in the order winning each field; each answer kept for as long as given. All are off until switched on here; the name searched for is sent to the authority.') }}</p>
        <table><thead><tr><th scope="col">{{ __('Authority') }}</th><th scope="col">{{ __('On') }}</th><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Calls a minute') }}</th><th scope="col">{{ __('Kept for (seconds)') }}</th><th scope="col">{{ __('Username') }}</th></tr></thead><tbody>
            @foreach($sources as $notation => $s)<tr>
                <td>{{ $sourceTerms[$notation]?->label(\App\Support\Vocab::languages()) }}<br><span class="meta">{{ \App\Authority\Settings::LICENCES[$notation][0] ?? '' }}</span></td>
                <td>@if($s['searched'])<input type="hidden" name="sources[{{ $notation }}][enabled]" value="0"><input type="checkbox" name="sources[{{ $notation }}][enabled]" value="1" style="width: auto" @checked($s['enabled']) aria-label="{{ __('On') }}">@else<span class="meta">{{ __('not yet searched') }}</span>@endif</td>
                <td><input name="sources[{{ $notation }}][precedence]" type="number" min="0" max="100" value="{{ $s['precedence'] }}" style="max-width: 5rem" aria-label="{{ __('Order') }}"></td>
                <td><input name="sources[{{ $notation }}][rate_limit]" type="number" min="1" max="1000" value="{{ $s['rate_limit'] }}" style="max-width: 6rem" aria-label="{{ __('Calls a minute') }}"></td>
                <td><input name="sources[{{ $notation }}][cache_ttl]" type="number" min="60" value="{{ $s['cache_ttl'] }}" style="max-width: 8rem" aria-label="{{ __('Kept for (seconds)') }}"></td>
                <td>@if($notation === 'geonames')<input name="sources[geonames][username]" value="{{ $s['username'] }}" maxlength="200" aria-label="{{ __('Username') }}" placeholder="{{ __('Your GeoNames username') }}">@endif</td>
            </tr>@endforeach
        </tbody></table>
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
