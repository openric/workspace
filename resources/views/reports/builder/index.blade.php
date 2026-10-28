{{-- OpenRiC Workspace - the report builder: the reports and templates (roadmap phase 8; Heratio's report-builder index and templates, ahg-reports). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Report builder'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Report builder') }}</h1>
    @include('reports._menu')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Build a report on one kind of entity: choose its columns from the elements of its standard, filter and sort it, and arrange it in sections. It downloads as CSV, XLSX, PDF or Word.') }}</p>

    <h2>{{ __('Reports') }}</h2>
    @if($reports->isEmpty())<p>{{ __('None.') }}</p>@else
        <table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Status') }}</th><th>{{ __('Owner') }}</th><th>{{ __('Updated') }}</th></tr></thead><tbody>
            @foreach($reports as $report)
                <tr><td><a href="{{ route('reports.builder.show', $report) }}">{{ $report->name }}</a>@if($report->shared) <span class="meta">{{ __('shared') }}</span>@endif</td>
                    <td>{{ __(\App\Reports\ReportBuilder::SOURCE_TITLES[$report->source] ?? $report->source) }}</td><td>{{ $report->status->label($lang) }}</td>
                    <td>{{ $report->owner->name }}</td><td>{{ $report->updated_at->format('Y-m-d H:i') }}</td></tr>
            @endforeach
        </tbody></table>
    @endif

    <h2>{{ __('New report') }}</h2>
    @error('source')<p role="alert" class="error">{{ $message }}</p>@enderror
    <form method="post" action="{{ route('reports.builder.store') }}">
        @csrf
        <p><label>{{ __('Name') }} <input name="name" required maxlength="255" value="{{ old('name') }}"></label></p>
        <p><label>{{ __('Kind') }} <select name="source">
            @foreach($sources as $kind => $title)<option value="{{ $kind }}" @selected(old('source') === $kind)>{{ $title }}</option>@endforeach
        </select></label></p>
        <p><label><input type="checkbox" name="shared" value="1"> {{ __('Shared with everyone who may read its kind') }}</label></p>
        <button type="submit">{{ __('Create') }}</button>
    </form>

    <h2>{{ __('Templates') }}</h2>
    @if($templates->isEmpty())<p>{{ __('None.') }}</p>@else
        <ul>
            @foreach($templates as $template)
                <li>
                    <form method="post" action="{{ route('reports.builder.store') }}" style="display: inline">
                        @csrf
                        <input type="hidden" name="template" value="{{ $template->id }}">
                        <input type="hidden" name="name" value="{{ $template->name }}">
                        <strong>{{ $template->name }}</strong> <span class="meta">{{ __(\App\Reports\ReportBuilder::SOURCE_TITLES[$template->structure['source']] ?? '') }}@if($template->isBundled()) · {{ __('bundled') }}@endif</span>
                        @if($template->description)<br><span>{{ $template->description }}</span>@endif
                        <br><button type="submit">{{ __('Start a report from it') }}</button>
                    </form>
                    @if(! $template->isBundled() && $template->created_by_id === auth()->id())
                        <form method="post" action="{{ route('reports.builder.templates.destroy', $template) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@endsection
