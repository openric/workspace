{{-- OpenRiC Workspace - building a report: its columns, filters, sort and sections (roadmap phase 8; Heratio's report-builder edit, ahg-reports; PSIS's editSuccess). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Edit').' '.$report->name)
@section('content')
    <h1>{{ $report->name }}</h1>
    @include('reports._menu')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><a href="{{ route('reports.builder.show', $report) }}">{{ __('View the report') }}</a> · {{ __(\App\Reports\ReportBuilder::SOURCE_TITLES[$report->source]) }}</p>

    <form method="post" action="{{ route('reports.builder.update', $report) }}">
        @csrf
        <p><label>{{ __('Name') }} <input name="name" required maxlength="255" value="{{ old('name', $report->name) }}"></label></p>
        <p><label>{{ __('Description') }}<br><textarea name="description" rows="3" maxlength="5000">{{ old('description', $report->description) }}</textarea></label></p>

        <fieldset><legend>{{ __('Columns') }}</legend>
            <p class="meta">{{ __('The columns of its tables, in this order; the elements are those of its standard.') }}</p>
            @foreach($columns as $key => $label)
                <label style="display: block"><input type="checkbox" name="columns[]" value="{{ $key }}" @checked(in_array($key, old('columns', $report->columns), true))> {{ $label }}</label>
            @endforeach
        </fieldset>

        <fieldset><legend>{{ __('Filters') }}</legend>
            @php($f = old('filters', $report->filters ?? []))
            <div class="filters" style="display: flex; flex-wrap: wrap; gap: .6rem 1rem">
                <label>{{ __('Date start') }} <input type="date" name="filters[from]" value="{{ $f['from'] ?? '' }}"></label>
                <label>{{ __('Date end') }} <input type="date" name="filters[to]" value="{{ $f['to'] ?? '' }}"></label>
                <label>{{ __('Date of') }} <select name="filters[of]"><option value="created">{{ __('Creation') }}</option><option value="updated" @selected(($f['of'] ?? '') === 'updated')>{{ __('Revision') }}</option></select></label>
                <label>{{ __('Published') }} <select name="filters[published]"><option value="">{{ __('All') }}</option>
                    <option value="yes" @selected(($f['published'] ?? '') === 'yes')>{{ __('Yes') }}</option><option value="no" @selected(($f['published'] ?? '') === 'no')>{{ __('No') }}</option></select></label>
                @if($levels->isNotEmpty())
                    <label>{{ __('Level of description') }} <select name="filters[level]"><option value="">{{ __('All') }}</option>
                        @foreach($levels as $level)<option value="{{ $level->id }}" @selected(($f['level'] ?? '') === $level->id)>{{ $level->label($lang) }}</option>@endforeach</select></label>
                @endif
                @if($repositories->isNotEmpty())
                    <label>{{ __('Repository') }} <select name="filters[repository]"><option value="">{{ __('All') }}</option>
                        @foreach($repositories as $repository)<option value="{{ $repository->id }}" @selected(($f['repository'] ?? '') === $repository->id)>{{ \App\Relations\EntityKinds::label($repository) }}</option>@endforeach</select></label>
                @endif
                @if($types->isNotEmpty())
                    <label>{{ __('Type') }} <select name="filters[type]"><option value="">{{ __('All') }}</option>
                        @foreach($types as $type)<option value="{{ $type->id }}" @selected(($f['type'] ?? '') === $type->id)>{{ $type->label($lang) }}</option>@endforeach</select></label>
                @endif
            </div>
        </fieldset>

        <fieldset><legend>{{ __('Sort') }}</legend>
            @php($sort = old('sort', $report->sort ?? []))
            <label>{{ __('Sort by') }} <select name="sort[by]">
                @foreach(['name' => __('Name'), 'created' => __('Created'), 'updated' => __('Updated')] as $by => $label)<option value="{{ $by }}" @selected(($sort['by'] ?? 'name') === $by)>{{ $label }}</option>@endforeach
            </select></label>
            <label>{{ __('Direction') }} <select name="sort[direction]">
                <option value="asc">{{ __('Ascending') }}</option><option value="desc" @selected(($sort['direction'] ?? '') === 'desc')>{{ __('Descending') }}</option>
            </select></label>
        </fieldset>

        <p><label><input type="hidden" name="shared" value="0"><input type="checkbox" name="shared" value="1" @checked(old('shared', $report->shared))> {{ __('Shared with everyone who may read its kind') }}</label></p>
        <button type="submit">{{ __('Save') }}</button>
    </form>

    <h2>{{ __('Sections') }}</h2>
    @forelse($report->sections as $section)
        @php($type = $section->type->notation)
        <fieldset><legend>{{ $loop->iteration }}. {{ $section->type->label($lang) }}</legend>
            <form method="post" action="{{ route('reports.builder.sections.update', [$report, $section]) }}">
                @csrf
                <p><label>{{ __('Title') }} <input name="title" maxlength="255" value="{{ $section->title }}"></label></p>
                @if($type === 'text')<p><label>{{ __('Text') }}<br><textarea name="content" rows="5" maxlength="20000">{{ $section->content }}</textarea></label></p>@endif
                @if($type === 'counts')
                    <p><label>{{ __('Count by') }} <select name="by">@foreach($countBy as $by => $label)<option value="{{ $by }}" @selected(($section->config['by'] ?? '') === $by)>{{ $label }}</option>@endforeach</select></label></p>
                @endif
                <button type="submit">{{ __('Save') }}</button>
            </form>
            @foreach(['up' => __('Move up'), 'down' => __('Move down')] as $direction => $label)
                <form method="post" action="{{ route('reports.builder.sections.move', [$report, $section]) }}" style="display: inline">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button type="submit" @disabled(($direction === 'up' && $loop->parent->first) || ($direction === 'down' && $loop->parent->last))>{{ $label }}</button></form>
            @endforeach
            <form method="post" action="{{ route('reports.builder.sections.destroy', [$report, $section]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>
        </fieldset>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse

    <h3>{{ __('Add a section') }}</h3>
    <form method="post" action="{{ route('reports.builder.sections.store', $report) }}">
        @csrf
        <p><label>{{ __('Type') }} <select name="type">@foreach($sectionTypes as $sectionType)<option value="{{ $sectionType->notation }}">{{ $sectionType->label($lang) }}</option>@endforeach</select></label>
            <label>{{ __('Title') }} <input name="title" maxlength="255"></label></p>
        <p><label>{{ __('Count by') }} <select name="by"><option value="">-</option>@foreach($countBy as $by => $label)<option value="{{ $by }}">{{ $label }}</option>@endforeach</select></label> <span class="meta">{{ __('for a counts section') }}</span></p>
        <p><label>{{ __('Text') }} <span class="meta">{{ __('for a text section; a blank line starts a new paragraph') }}</span><br><textarea name="content" rows="4" maxlength="20000"></textarea></label></p>
        <button type="submit">{{ __('Add') }}</button>
    </form>

    <h2>{{ __('Remove the report') }}</h2>
    <form method="post" action="{{ route('reports.builder.destroy', $report) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>
@endsection
