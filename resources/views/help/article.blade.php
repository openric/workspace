{{-- OpenRiC Workspace - one guide of the Help Center: its contents, text, related guides and the guides before and after it (Heratio's help article; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends($layout)
@section('title', $article->title)
@section('content')
    @include('help._nav', ['current' => $article->category])
    <article class="site-text help-article">
        <p class="meta"><a href="{{ route('help.category', \App\Help\SystemMaps::slug($article->category)) }}">{{ __($article->category) }}</a>@if($article->subcategory) · {{ __($article->subcategory) }}@endif · {{ trans_choice(':count word|:count words', $article->word_count, ['count' => number_format($article->word_count)]) }}</p>
        @if(count($toc) > 2)
            <nav aria-label="{{ __('Contents') }}" style="border: 1px solid var(--line); border-radius: var(--radius); padding: .6rem 1rem; margin: 1rem 0"><strong>{{ __('Contents') }}</strong>
                <ul style="margin: .3rem 0">@foreach($toc as $t)@if($t['level'] <= 3)<li style="margin-left: {{ ($t['level'] - 2) * 1.2 }}rem"><a href="#{{ $t['anchor'] }}">{{ $t['text'] }}</a></li>@endif @endforeach</ul></nav>
        @endif
        {!! $article->body_html !!}
        @if($related->isNotEmpty())
            <h2>{{ __('Related guides') }}</h2>
            <ul>@foreach($related as $r)<li><a href="{{ route('help.article', $r->slug) }}">{{ $r->title }}</a> <span class="meta">{{ __($r->category) }}</span></li>@endforeach</ul>
        @endif
        <p class="meta" style="display: flex; justify-content: space-between; gap: 1rem; margin-top: 2rem">
            <span>@if($adjacent['prev'])<a href="{{ route('help.article', $adjacent['prev']->slug) }}" rel="prev">{{ __('Before: :title', ['title' => $adjacent['prev']->title]) }}</a>@endif</span>
            <span>@if($adjacent['next'])<a href="{{ route('help.article', $adjacent['next']->slug) }}" rel="next">{{ __('Next: :title', ['title' => $adjacent['next']->title]) }}</a>@endif</span>
        </p>
        @auth<p class="meta">{{ $article->source_file }}@if($article->heratio_source) · {{ __('adapted from Heratio\'s :guide', ['guide' => $article->heratio_source]) }}@endif
            @if(auth()->user()->hasPermission(\App\Support\Vocab::permission('manage-institution'))) · <a href="{{ route('help.links', $article->slug) }}">{{ __('Link to other guides') }}</a>@endif</p>@endauth
    </article>
@endsection
