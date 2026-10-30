{{-- OpenRiC Workspace - the public catalogue: what it holds, and search (WS-009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $query !== '' ? __('Search: :query', ['query' => $query]) : __('Catalogue'))
@section('content')
    @if($query === '')
        {{-- The hero, as openric.org's: the institution's own introduction, what is published, and search. --}}
        <section class="catalogue-hero">
            <h1>{{ $institution->catalogue_heading ?? __('The catalogue of :institution', ['institution' => $institution->name]) }}</h1>
            @if($institution->catalogue_introduction)<div class="catalogue-lede">@include('discover._text', ['text' => $institution->catalogue_introduction])</div>@endif
            @if($institution->catalogue_show_counts)<p class="catalogue-counts">{{ __(':records archival descriptions, :agents people, families and organisations, :activities activities and :places places are published here, described in Records in Contexts.', $counts->all()) }}</p>@endif
            <form method="get" action="{{ route('discover.home') }}" role="search" class="catalogue-search">
                <label for="q">{{ __('Search names, titles and reference codes') }}</label>
                <div><input id="q" name="q" required><button type="submit">{{ __('Search') }}</button></div>
            </form>
        </section>
        @if($featured->isNotEmpty())
            <h2>{{ __('Explore the collections') }}</h2>
            @include('discover._carousel', ['featured' => $featured, 'view' => $view])
        @endif
        @if($tops->isNotEmpty())
            <h2>{{ __('All collections') }}</h2>
            <ul>@foreach($tops as $top)<li><a href="{{ route('discover.record', $top->id) }}">{{ $view->label($top) }}</a> <span class="meta">{{ collect([$top->level->label(\App\Support\Vocab::languages()), $top->referenceCode()])->filter()->join(', ') }}</span></li>@endforeach</ul>
        @endif
        @if($repositories->isNotEmpty())
            <h2>{{ __('Repositories') }}</h2>
            <ul>@foreach($repositories as $repository)<li><a href="{{ route('discover.repository', $repository->id) }}">{{ $view->label($repository) }}</a></li>@endforeach</ul>
        @endif
        @if($counts->sum() === 0)<p>{{ __('Nothing is published yet.') }}</p>@endif
        @if($institution->catalogue_credits)
            <section class="catalogue-credits" aria-labelledby="credits">
                <h2 id="credits">{{ __('Credits') }}</h2>
                @include('discover._text', ['text' => $institution->catalogue_credits])
            </section>
        @endif
    @else
        @php($lang = \App\Support\Vocab::languages())
        @php($link = fn (array $change) => route('discover.home', array_filter([...['q' => $query, 'kind' => $kind] + $search['filters'], ...$change], fn ($v) => $v !== null && $v !== '')))
        <h1>{{ __('Search: :query', ['query' => $query]) }}</h1>
        <p role="status">{{ $search['total'] === 0 ? __('No results found.') : __(':count found.', ['count' => $search['total']]) }}</p>
        <div class="search-layout">
            <aside class="search-facets" aria-label="{{ __('Narrow the results') }}">
                <h2>{{ __('Kind') }}</h2>
                <ul class="facets">
                    @if($kind)<li><a href="{{ $link(['kind' => null, 'page' => null]) }}">{{ __('Everything') }}</a></li>@endif
                    @foreach($search['facets']['kind'] as $key => $n)<li>@if($kind === $key)<strong>{{ __($kinds[$key]) }}</strong>@else<a href="{{ $link(['kind' => $key, 'page' => null]) }}">{{ __($kinds[$key]) }}</a>@endif <span class="meta">{{ $n }}</span></li>@endforeach
                </ul>
                @if($search['facets']['level'] !== [])
                    <h2>{{ __('Level of description') }}</h2>
                    <ul class="facets">
                        @if($search['filters']['level'])<li><a href="{{ $link(['level' => null, 'page' => null]) }}">{{ __('Any') }}</a></li>@endif
                        @foreach($search['facets']['level'] as $id => $n)@if($concept = $search['levels']->get($id))<li>@if($search['filters']['level'] === $id)<strong>{{ $concept->label($lang) }}</strong>@else<a href="{{ $link(['level' => $id, 'page' => null]) }}">{{ $concept->label($lang) }}</a>@endif <span class="meta">{{ $n }}</span></li>@endif @endforeach
                    </ul>
                @endif
                @if($search['facets']['repository'] !== [])
                    <h2>{{ __('Repository') }}</h2>
                    <ul class="facets">
                        @if($search['filters']['repository'])<li><a href="{{ $link(['repository' => null, 'page' => null]) }}">{{ __('Any') }}</a></li>@endif
                        @foreach($search['facets']['repository'] as $id => $n)@if($repository = $search['repositories']->get($id))<li>@if($search['filters']['repository'] === $id)<strong>{{ $view->label($repository) }}</strong>@else<a href="{{ $link(['repository' => $id, 'page' => null]) }}">{{ $view->label($repository) }}</a>@endif <span class="meta">{{ $n }}</span></li>@endif @endforeach
                    </ul>
                @endif
                <h2>{{ __('Dates') }}</h2>
                <form method="get" action="{{ route('discover.home') }}" class="facet-years">
                    @foreach(array_filter(['q' => $query, 'kind' => $kind, 'level' => $search['filters']['level'], 'repository' => $search['filters']['repository']]) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                    <label for="from">{{ __('From year') }}</label><input id="from" name="from" inputmode="numeric" size="5" value="{{ $search['filters']['from'] }}">
                    <label for="to">{{ __('To year') }}</label><input id="to" name="to" inputmode="numeric" size="5" value="{{ $search['filters']['to'] }}">
                    <button type="submit">{{ __('Apply') }}</button>
                </form>
            </aside>
            <div>
                <ul>
                    @foreach($search['results'] as $row)
                        <li><a href="{{ \App\Discovery\Links::page($row['entity']) }}">{{ $row['title'] }}</a>
                            <span class="meta">{{ $row['entity'] instanceof \App\Models\RecordResource ? collect([$row['entity']->level?->label($lang), $row['entity']->referenceCode()])->filter()->join(', ') : __(\App\Relations\EntityKinds::noun($row['entity'])) }}</span>
                            <span class="snippet">{{ $row['snippet'] }}</span></li>
                    @endforeach
                </ul>
                @if($search['total'] > 20)
                    <p>@if($search['page'] > 1)<a href="{{ $link(['page' => $search['page'] - 1]) }}">{{ __('Previous page') }}</a> · @endif
                        {{ __('Page :page of :pages', ['page' => $search['page'], 'pages' => (int) ceil($search['total'] / 20)]) }}
                        @if($search['page'] * 20 < $search['total']) · <a href="{{ $link(['page' => $search['page'] + 1]) }}">{{ __('Next page') }}</a>@endif</p>
                @endif
                <p class="meta">{{ __('Use quotes for a phrase, such as "town council"; a minus sign leaves a word out, such as council -minutes; OR finds either word.') }}</p>
            </div>
        </div>
    @endif
@endsection
