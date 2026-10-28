{{-- OpenRiC Workspace - a built report (roadmap phase 8; Heratio's report-builder view and preview, ahg-reports). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $report->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $report->name }}</h1>
    @include('reports._menu')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($report->description)<p>{{ $report->description }}</p>@endif
    <p class="meta">{{ $document['source'] }} · {{ $document['status'] }} · {{ __('Owner') }}: {{ $report->owner->name }} · {{ __('Generated') }} {{ $document['generated'] }}</p>
    <p>{{ __('Download') }}:
        @foreach(['csv' => 'CSV', 'xlsx' => 'XLSX', 'pdf' => 'PDF', 'docx' => 'Word'] as $format => $label)<a href="{{ route('reports.builder.export', [$report, $format]) }}">{{ $label }}</a>@if(! $loop->last) · @endif @endforeach
    </p>
    <div>
        @if($mayEdit)<a href="{{ route('reports.builder.edit', $report) }}">{{ __('Edit') }}</a> · @endif
        <form method="post" action="{{ route('reports.builder.copy', $report) }}" style="display: inline">@csrf<button type="submit">{{ __('Copy') }}</button></form>
    </div>
    @if($mayEdit)
        <form method="post" action="{{ route('reports.builder.status', $report) }}" style="display: inline">
            @csrf
            <label>{{ __('Status') }} <select name="status">@foreach($statuses as $status)<option value="{{ $status->id }}" @selected($status->id === $report->status_id)>{{ $status->label($lang) }}</option>@endforeach</select></label>
            <button type="submit">{{ __('Change') }}</button>
        </form>
    @endif
    <form method="post" action="{{ route('reports.builder.template', $report) }}" style="display: inline">
        @csrf
        <label>{{ __('Save as a template named') }} <input name="name" required maxlength="255" value="{{ $report->name }}"></label>
        <button type="submit">{{ __('Save') }}</button>
    </form>
    @include('reports.builder._document', ['document' => $document, 'limit' => 100])
@endsection
