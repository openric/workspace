{{-- OpenRiC Workspace - the Help Center: its categories, a search, the guides changed last, and the system map and breakdown (Heratio's Help Center; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends($layout)
@section('title', __('Help Center'))
@section('content')
    <h1>{{ __('Help Center') }}</h1>
    <p>{{ trans_choice('{1} One guide to what the Workspace does and how to do it.|[2,*] :count guides to what the Workspace does and how to do it.', $total, ['count' => $total]) }}</p>
    @include('help._search')
    <h2>{{ __('Categories') }}</h2>
    <ul class="lp-cards" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); gap: .8rem; list-style: none; padding: 0">
        @foreach($categories as $c)<li style="border: 1px solid var(--line); border-radius: var(--radius); padding: .7rem .9rem; margin: 0"><a href="{{ route('help.category', \App\Help\SystemMaps::slug($c->category)) }}">{{ __($c->category) }}</a><br><span class="meta">{{ trans_choice(':count guide|:count guides', $c->articles, ['count' => $c->articles]) }}</span></li>@endforeach
    </ul>
    <h2>{{ __('How it fits together') }}</h2>
    <p><a href="{{ route('help.system-map') }}">{{ __('The system map') }}</a>: {{ __('how a record moves through the Workspace, from intake to research.') }}<br>
        <a href="{{ route('help.system-breakdown') }}">{{ __('The system breakdown') }}</a>: {{ __('what the Workspace does for each kind of entity it describes.') }}<br>
        <a href="{{ route('api.openapi') }}">{{ __('The API, as OpenAPI') }}</a></p>
    @if($recent->isNotEmpty())
        <h2>{{ __('Changed most recently') }}</h2>
        <ul>@foreach($recent as $r)<li><a href="{{ route('help.article', $r->slug) }}">{{ $r->title }}</a> <span class="meta">{{ __($r->category) }}</span></li>@endforeach</ul>
    @endif
@endsection
