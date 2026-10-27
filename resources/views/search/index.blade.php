{{-- OpenRiC Workspace - full-text search for staff (WS-016, roadmap phase 6, ADR 0009). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $query === '' ? __('Search') : __('Search: :query', ['query' => $query]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($kinds = ['records' => 'Archival descriptions', 'agents' => 'Authority records', 'activities' => 'Activities', 'places' => 'Places', 'repositories' => 'Repositories'])
    @php($link = fn (array $change) => route('search', array_filter([...['q' => $query, 'kind' => $kind] + $filters, ...$change], fn ($v) => $v !== null && $v !== '')))
    <h1>{{ __('Search') }}</h1>
    <form method="get" action="{{ route('search') }}" role="search" style="display: flex; gap: .5rem; flex-wrap: wrap; max-width: 40rem">
        <label for="search-q" style="position: absolute; left: -999px">{{ __('Search') }}</label>
        <input id="search-q" name="q" value="{{ $query }}" style="flex: 1 1 16rem" autofocus>
        <button type="submit" style="margin: 0">{{ __('Search') }}</button>
    </form>
    <p class="meta">{{ $drafts ? __('Drafts are included.') : __('Published values only.') }} {{ __('Use quotes for a phrase, such as "town council"; a minus sign leaves a word out, such as council -minutes; OR finds either word.') }}</p>
    @if($query !== '')
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
                        @if($filters['level'])<li><a href="{{ $link(['level' => null, 'page' => null]) }}">{{ __('Any') }}</a></li>@endif
                        @foreach($search['facets']['level'] as $id => $n)@if($concept = $levels->get($id))<li><a href="{{ $link(['level' => $id, 'page' => null]) }}">{{ $concept->label($lang) }}</a> <span class="meta">{{ $n }}</span></li>@endif @endforeach
                    </ul>
                @endif
                @if($search['facets']['repository'] !== [])
                    <h2>{{ __('Repository') }}</h2>
                    <ul class="facets">
                        @if($filters['repository'])<li><a href="{{ $link(['repository' => null, 'page' => null]) }}">{{ __('Any') }}</a></li>@endif
                        @foreach($search['facets']['repository'] as $id => $n)@if($repository = $repositories->get($id))<li><a href="{{ $link(['repository' => $id, 'page' => null]) }}">{{ \App\Relations\EntityKinds::label($repository) }}</a> <span class="meta">{{ $n }}</span></li>@endif @endforeach
                    </ul>
                @endif
                <h2>{{ __('Dates') }}</h2>
                <form method="get" action="{{ route('search') }}" class="facet-years">
                    @foreach(array_filter(['q' => $query, 'kind' => $kind, 'level' => $filters['level'], 'repository' => $filters['repository']]) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                    <label for="from">{{ __('From year') }}</label><input id="from" name="from" inputmode="numeric" size="5" value="{{ $filters['from'] }}">
                    <label for="to">{{ __('To year') }}</label><input id="to" name="to" inputmode="numeric" size="5" value="{{ $filters['to'] }}">
                    <button type="submit">{{ __('Apply') }}</button>
                </form>
            </aside>
            <div>
                <ul>
                    @foreach($search['results'] as $row)
                        <li><a href="{{ \App\Relations\EntityKinds::route($row['entity']) }}">{{ $row['title'] ?: \App\Relations\EntityKinds::label($row['entity']) }}</a>
                            <span class="meta">{{ $row['entity'] instanceof \App\Models\RecordResource ? collect([$row['entity']->level?->label($lang), $row['entity']->referenceCode()])->filter()->join(', ') : __(\App\Relations\EntityKinds::noun($row['entity'])) }}</span>
                            <span class="snippet">{{ $row['snippet'] }}</span></li>
                    @endforeach
                </ul>
                @if($search['total'] > $perPage)
                    <p>@if($page > 1)<a href="{{ $link(['page' => $page - 1]) }}">{{ __('Previous page') }}</a> · @endif
                        {{ __('Page :page of :pages', ['page' => $page, 'pages' => (int) ceil($search['total'] / $perPage)]) }}
                        @if($page * $perPage < $search['total']) · <a href="{{ $link(['page' => $page + 1]) }}">{{ __('Next page') }}</a>@endif</p>
                @endif
            </div>
        </div>
    @endif
@endsection
