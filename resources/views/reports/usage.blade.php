{{-- OpenRiC Workspace - usage statistics of the public catalogue (roadmap phase 8; PSIS's statistics dashboard, views, downloads, top items, item, repository and bots pages, ahgStatisticsPlugin). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Usage statistics'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($s = $statistics)
    @php($kinds = \App\Relations\EntityKinds::class)
    <h1>{{ __('Usage statistics') }}</h1>
    @include('reports._menu')
    <p>{{ __('How the public catalogue is used: the pages viewed and the files downloaded. Web crawlers are counted apart, and no visitor\'s address is kept. The counts are made each hour.') }}</p>
    <form method="get" action="{{ route('reports.usage') }}" class="filters">
        <label>{{ __('Date start') }} <input type="date" name="from" value="{{ $from }}"></label>
        <label>{{ __('Date end') }} <input type="date" name="to" value="{{ $to }}"></label>
        <label>{{ __('Repository') }} <select name="repository"><option value="">{{ __('All') }}</option>
            @foreach($repositories as $repository)<option value="{{ $repository->id }}" @selected(($filters['repository'] ?? '') === $repository->id)>{{ $kinds::label($repository) }}</option>@endforeach
        </select></label>
        @if($subject)<input type="hidden" name="subject" value="{{ $subject->getKey() }}">@endif
        <button type="submit">{{ __('Search') }}</button>
    </form>
    @if($subject)
        <p>{{ __('For :name only.', ['name' => $kinds::label($subject)]) }} <a href="{{ route('reports.usage', ['from' => $from, 'to' => $to]) }}">{{ __('The whole catalogue') }}</a></p>
    @endif

    <table><thead><tr><th>{{ __('Views') }}</th><th>{{ __('Downloads') }}</th>@if($s['visitors'] !== null)<th>{{ __('Visitors') }}</th>@endif<th>{{ __('By signed-in users') }}</th><th>{{ __('By web crawlers') }}</th></tr></thead>
        <tbody><tr><td>{{ $s['views'] }}</td><td>{{ $s['downloads'] }}</td>@if($s['visitors'] !== null)<td>{{ $s['visitors'] }}</td>@endif<td>{{ $s['signed_in'] }}</td><td>{{ $s['crawlers'] }}</td></tr></tbody></table>
    @if($s['visitors'] !== null)<p class="meta">{{ __('A visitor is counted once a day, so one who came on three days counts three times.') }}</p>@endif

    <h2>{{ __('Each day') }} <small><a href="{{ route('reports.usage', [...request()->except('page'), 'format' => 'csv', 'by' => 'day']) }}">CSV</a></small></h2>
    @php($most = max(1, $s['days']->max(fn ($d) => max($d['views'], $d['downloads']))))
    <table><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Views') }}</th><th>{{ __('Downloads') }}</th></tr></thead><tbody>
        @foreach($s['days']->reverse() as $day => $counts)
            <tr><td>{{ $day }}</td>
                @foreach(['views', 'downloads'] as $what)<td><span class="bar" style="width: {{ round(6 * $counts[$what] / $most, 2) }}rem" aria-hidden="true"></span> {{ $counts[$what] }}</td>@endforeach
            </tr>
        @endforeach
    </tbody></table>

    @unless($subject)
        @foreach(['viewed' => __('Most viewed'), 'downloaded' => __('Most downloaded')] as $key => $heading)
            <h2>{{ $heading }} @if($key === 'viewed')<small><a href="{{ route('reports.usage', [...request()->except('page'), 'format' => 'csv', 'by' => 'item']) }}">CSV</a></small>@endif</h2>
            @if($s[$key]->isEmpty())<p>{{ __('None.') }}</p>@else
                <table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Count') }}</th></tr></thead><tbody>
                    @foreach($s[$key] as $row)
                        <tr><td><a href="{{ route('reports.usage', ['from' => $from, 'to' => $to, 'subject' => $row['entity']->getKey()]) }}">{{ $kinds::label($row['entity']) }}</a></td>
                            <td>{{ __($kinds::noun($row['entity'])) }}</td><td>{{ $row['count'] }}</td></tr>
                    @endforeach
                </tbody></table>
            @endif
        @endforeach

        @unless($filters['repository'] ?? null)
            <h2>{{ __('By repository') }}</h2>
            @if($s['repositories']->isEmpty())<p>{{ __('None.') }}</p>@else
                <table><thead><tr><th>{{ __('Repository') }}</th><th>{{ __('Views') }}</th><th>{{ __('Downloads') }}</th></tr></thead><tbody>
                    @foreach($s['repositories'] as $row)<tr><td><a href="{{ route('reports.usage', ['from' => $from, 'to' => $to, 'repository' => $row['repository']->id]) }}">{{ $kinds::label($row['repository']) }}</a></td><td>{{ $row['views'] }}</td><td>{{ $row['downloads'] }}</td></tr>@endforeach
                </tbody></table>
            @endif
        @endunless
    @endunless

    <h2>{{ __('Web crawlers') }}</h2>
    <p class="meta">{{ __('Programs that read the catalogue, known by the web crawlers vocabulary; from the events of the last :days days.', ['days' => config('workspace.usage_retention_days')]) }}</p>
    @if($s['by_crawler']->isEmpty())<p>{{ __('None.') }}</p>@else
        <table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Count') }}</th></tr></thead><tbody>
            @foreach($s['by_crawler'] as $row)<tr><td><a href="{{ $row['crawler']->iri }}" rel="noopener">{{ $row['crawler']->label($lang) }}</a></td><td>{{ $row['count'] }}</td></tr>@endforeach
        </tbody></table>
    @endif
@endsection
