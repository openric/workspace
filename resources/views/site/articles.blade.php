{{-- OpenRiC Workspace - the institution's articles: nine to a page, by group, sorted, as tiles or a list (Heratio's articles index; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Articles'))
@section('head')
    <style>
        .article-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); gap: 1rem; list-style: none; padding: 0; }
        .article-tiles li { border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; background: var(--surface); margin: 0; }
        .article-tiles img { width: 100%; height: 10rem; object-fit: cover; display: block; }
        .article-tiles div { padding: .8rem 1rem; } .article-tiles h2 { font-size: 1.05rem; margin: 0 0 .3rem; border: 0; padding: 0; }
        .article-filters { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; margin: 1rem 0; }
    </style>
@endsection
@section('content')
    <h1>{{ __('Articles') }}</h1>
    @php($link = fn (array $change) => route('site.articles-public', array_filter(['group' => $group, 'sort' => $sort === 'latest' ? null : $sort, 'view' => $display === 'list' ? 'list' : null, ...$change])))
    @if($groups)
        <nav class="article-filters" aria-label="{{ __('Groups') }}">
            @if($group)<a href="{{ $link(['group' => null]) }}">{{ __('All articles') }}</a>@else<strong>{{ __('All articles') }}</strong>@endif
            @foreach($groups as $g)@if($g === $group)<strong>{{ $g }}</strong>@else<a href="{{ $link(['group' => $g]) }}">{{ $g }}</a>@endif @endforeach
        </nav>
    @endif
    <form method="get" action="{{ route('site.articles-public') }}" class="article-filters">
        @if($group)<input type="hidden" name="group" value="{{ $group }}">@endif
        <label for="as">{{ __('Sort') }}</label>
        <select id="as" name="sort" onchange="this.form.submit()">@foreach(['latest' => __('Newest first'), 'oldest' => __('Oldest first'), 'title' => __('By title'), 'popular' => __('Most read')] as $k => $v)<option value="{{ $k }}" @selected($sort === $k)>{{ $v }}</option>@endforeach</select>
        <label for="av">{{ __('Show as') }}</label>
        <select id="av" name="view" onchange="this.form.submit()"><option value="tile" @selected($display === 'tile')>{{ __('Tiles') }}</option><option value="list" @selected($display === 'list')>{{ __('A list') }}</option></select>
        <noscript><button type="submit">{{ __('Show') }}</button></noscript>
    </form>
    @if($articles->isEmpty())
        <p>{{ __('No articles yet.') }}</p>
    @elseif($display === 'list')
        <ul>@foreach($articles as $a)<li><a href="{{ route('site.article', $a->slug) }}">{{ $a->title }}</a> <span class="meta">{{ ($a->published_at ?? $a->created_at)->format('j M Y') }}@if($a->article_group) · {{ $a->article_group }}@endif @if($a->attachments_count) · {{ trans_choice(':count file|:count files', $a->attachments_count, ['count' => $a->attachments_count]) }}@endif</span>@if($a->excerpt)<br>{{ $a->excerpt }}@endif</li>@endforeach</ul>
    @else
        <ul class="article-tiles">@foreach($articles as $a)<li>@if($a->cover)<a href="{{ route('site.article', $a->slug) }}"><img src="{{ route('site.file', $a->cover) }}" alt=""></a>@endif
            <div><h2><a href="{{ route('site.article', $a->slug) }}">{{ $a->title }}</a></h2><p class="meta">{{ ($a->published_at ?? $a->created_at)->format('j M Y') }}@if($a->author) · {{ $a->author }}@endif @if($a->article_group) · {{ $a->article_group }}@endif</p>@if($a->excerpt)<p>{{ $a->excerpt }}</p>@endif</div></li>@endforeach</ul>
    @endif
    {{ $articles->links() }}
@endsection
