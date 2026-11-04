{{-- OpenRiC Workspace - the guides of one category of the Help Center (Heratio's help category; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends($layout)
@section('title', __($category))
@section('content')
    @include('help._nav', ['current' => $category])
    <h1>{{ __($category) }}</h1>
    @include('help._search')
    @foreach($articles->groupBy(fn ($a) => $a->subcategory ?? '') as $sub => $list)
        @if($sub !== '')<h2>{{ __($sub) }}</h2>@endif
        <ul>@foreach($list as $a)<li><a href="{{ route('help.article', $a->slug) }}">{{ $a->title }}</a> <span class="meta">{{ trans_choice(':count word|:count words', $a->word_count, ['count' => number_format($a->word_count)]) }}</span></li>@endforeach</ul>
    @endforeach
@endsection
