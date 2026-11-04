{{-- OpenRiC Workspace - one catalogue of the code reference, filtered, a page of groups at a time (Heratio's functions docs; roadmap phase 20d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __($meta['title']))
@section('content')
    <h1>{{ __($meta['title']) }}</h1>
    <p><a href="{{ route('functions-docs.index') }}">{{ __('Code reference') }}</a> · <span class="meta">{{ trans_choice(':count entry|:count entries', $entries, ['count' => $entries]) }}</span></p>
    <form method="get" role="search"><label for="fq">{{ __('Filter') }}</label><input id="fq" name="q" value="{{ $q }}"> <button type="submit">{{ __('Filter') }}</button></form>
    @forelse($groups as $group => $items)
        <h2 style="font-size: 1.05rem">{{ __($meta['group']) }}: <code>{{ $group }}</code></h2>
        <table><tbody>@foreach($items as $e)<tr><td style="white-space: pre"><strong>{{ $e['name'] }}</strong></td><td><code>{{ $e['signature'] }}</code>@if($e['summary'] !== '')<br><span class="meta">{{ $e['summary'] }}</span>@endif</td><td class="meta">{{ $e['where'] }}</td></tr>@endforeach</tbody></table>
    @empty
        <p>{{ __('Nothing matches.') }}</p>
    @endforelse
    @if($groups->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>@if($groups->previousPageUrl())<a href="{{ $groups->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            {{ __('Page :page of :pages', ['page' => $groups->currentPage(), 'pages' => $groups->lastPage()]) }}
            @if($groups->nextPageUrl())<a href="{{ $groups->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif</p></nav>
    @endif
@endsection
