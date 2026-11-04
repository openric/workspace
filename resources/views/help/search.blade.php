{{-- OpenRiC Workspace - a search of the Help Center's guides and their sections (Heratio's help search; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends($layout)
@section('title', __('Search the help'))
@section('content')
    @include('help._nav')
    <h1>{{ __('Search the help') }}</h1>
    @include('help._search')
    @if($query !== '')
        <p role="status">{{ $results['articles']->isEmpty() && $results['sections']->isEmpty() ? __('No guide matches.') : trans_choice(':count guide matches.|:count guides match.', $results['articles']->count(), ['count' => $results['articles']->count()]) }}</p>
        @if($results['articles']->isNotEmpty())
            <ul>@foreach($results['articles'] as $r)<li><a href="{{ route('help.article', $r->slug) }}">{{ $r->title }}</a> <span class="meta">{{ __($r->category) }}</span><br>
                {{-- ts_headline marks the words found with <b>; everything else in it is escaped. --}}
                <span class="meta">{!! str_replace(['&lt;b&gt;', '&lt;/b&gt;'], ['<b>', '</b>'], e($r->snippet)) !!}</span></li>@endforeach</ul>
        @endif
        @if($results['sections']->isNotEmpty())
            <h2>{{ __('Sections') }}</h2>
            <ul>@foreach($results['sections'] as $s)<li><a href="{{ route('help.article', $s->slug) }}#{{ $s->anchor }}">{{ $s->heading }}</a> <span class="meta">{{ $s->article_title }}</span></li>@endforeach</ul>
        @endif
    @endif
@endsection
