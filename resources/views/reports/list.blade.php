{{-- OpenRiC Workspace - one report list, with its filters, pages and CSV (roadmap phase 8; Heratio's report pages and _filters, ahg-reports). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($title = __(\App\Http\Controllers\ReportController::TITLES[$list]))
@section('title', $title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $title }}</h1>
    @include('reports._menu')
    @if($list !== 'taxonomies')
        <form method="get" action="{{ route('reports.'.$list) }}" class="filters">
            <label>{{ __('Date start') }} <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label>{{ __('Date end') }} <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
            @if(in_array($list, ['descriptions', 'authorities', 'repositories'], true))
                <label>{{ __('Date of') }} <select name="of">
                    <option value="created">{{ __('Creation') }}</option>
                    <option value="updated" @selected(($filters['of'] ?? '') === 'updated')>{{ __('Revision') }}</option>
                </select></label>
                <label>{{ __('Published') }} <select name="published">
                    <option value="">{{ __('All') }}</option>
                    <option value="yes" @selected(($filters['published'] ?? '') === 'yes')>{{ __('Yes') }}</option>
                    <option value="no" @selected(($filters['published'] ?? '') === 'no')>{{ __('No') }}</option>
                </select></label>
            @endif
            @if($levels->isNotEmpty())
                <label>{{ __('Level of description') }} <select name="level"><option value="">{{ __('All') }}</option>
                    @foreach($levels as $level)<option value="{{ $level->id }}" @selected(($filters['level'] ?? '') === $level->id)>{{ $level->label($lang) }}</option>@endforeach
                </select></label>
            @endif
            @if($types->isNotEmpty())
                <label>{{ __('Type of entity') }} <select name="type"><option value="">{{ __('All') }}</option>
                    @foreach($types as $type)<option value="{{ $type->id }}" @selected(($filters['type'] ?? '') === $type->id)>{{ $type->label($lang) }}</option>@endforeach
                </select></label>
            @endif
            <label>{{ __('Results per page') }} <select name="per">
                @foreach(['20', '50', '100'] as $per)<option @selected(($filters['per'] ?? '50') === $per)>{{ $per }}</option>@endforeach
            </select></label>
            <button type="submit">{{ __('Search') }}</button>
        </form>
    @endif
    <p>{{ trans_choice(':count result|:count results', $page->total()) }} · <a href="{{ route('reports.'.$list, [...request()->except('page'), 'format' => 'csv']) }}">{{ __('Download as CSV') }}</a></p>
    @if($list === 'activity')<p class="meta">{{ __('Each action of each user, counted; the audit log has every one of them.') }}</p>@endif
    @if($page->isEmpty())<p>{{ __('None.') }}</p>@else
        <table><thead><tr>@foreach($reports->header($list) as $heading)@if($heading !== 'IRI')<th>{{ $heading }}</th>@endif @endforeach</tr></thead><tbody>
            @foreach($page as $row)
                @php($cells = $reports->row($list, $row))
                <tr>@foreach($cells as $i => $cell)
                    @if($reports->header($list)[$i] === 'IRI') @continue @endif
                    @if($i === 0 && $row instanceof \Illuminate\Database\Eloquent\Model && ! $row instanceof \App\Models\Vocabulary)
                        <td><a href="{{ \App\Relations\EntityKinds::route($row) }}">{{ $cell }}</a></td>
                    @elseif($i === 0 && $row instanceof \App\Models\Vocabulary)
                        <td><a href="{{ $row->iri }}" rel="noopener">{{ $cell }}</a></td>
                    @else<td>{{ $cell }}</td>@endif
                @endforeach</tr>
            @endforeach
        </tbody></table>
        {{ $page->links() }}
    @endif
@endsection
