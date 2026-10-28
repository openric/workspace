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
    @if(session('share_link'))
        <p role="status">{{ __('The share link, shown only now:') }} <code>{{ session('share_link') }}</code></p>
    @endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @include('reports.builder._document', ['document' => $document, 'limit' => 100])

    <h2>{{ __('Files made') }}</h2>
    @if($archives->isEmpty())<p>{{ __('None.') }}</p>@else
        <ul>@foreach($archives as $archive)<li><a href="{{ route('reports.builder.archive', [$report, $archive]) }}">{{ $archive->generated_at->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i') }}, {{ strtoupper($archive->format) }}</a>
            <span class="meta">{{ \Illuminate\Support\Number::fileSize($archive->size_bytes, 1) }} · {{ trans_choice(':count entity|:count entities', $archive->entities) }}@if($archive->schedule_id) · {{ __('scheduled') }}@endif</span></li>@endforeach</ul>
    @endif
    @if($mayEdit)
        <form method="post" action="{{ route('reports.builder.generate', $report) }}">
            @csrf
            <label>{{ __('Make and keep a file now') }} <select name="format">@foreach(['pdf' => 'PDF', 'xlsx' => 'XLSX', 'docx' => 'Word', 'csv' => 'CSV'] as $format => $label)<option value="{{ $format }}">{{ $label }}</option>@endforeach</select></label>
            <button type="submit">{{ __('Make') }}</button>
        </form>

        <h2>{{ __('Schedules') }}</h2>
        <p class="meta">{{ __('Times are in :zone.', ['zone' => config('workspace.timezone')]) }}</p>
        @if($schedules->isEmpty())<p>{{ __('None.') }}</p>@else
            <ul>@foreach($schedules as $schedule)
                <li>{{ $schedule->frequency->label($lang) }}, {{ substr($schedule->time_of_day, 0, 5) }}, {{ strtoupper($schedule->format) }}, {{ trans_choice(':count recipient|:count recipients', count($schedule->recipients)) }}
                    <span class="meta">{{ __('next') }} {{ $schedule->next_run_at->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</span>
                    <form method="post" action="{{ route('reports.builder.schedules.destroy', [$report, $schedule]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></li>
            @endforeach</ul>
        @endif
        <form method="post" action="{{ route('reports.builder.schedules.store', $report) }}">
            @csrf
            <div style="display: flex; flex-wrap: wrap; gap: .6rem 1rem; align-items: end">
                <label>{{ __('How often') }} <select name="frequency">@foreach($frequencies as $frequency)<option value="{{ $frequency->notation }}">{{ $frequency->label($lang) }}</option>@endforeach</select></label>
                <label>{{ __('Day of the week') }} <select name="day_of_week">@foreach([1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')] as $day => $label)<option value="{{ $day }}">{{ $label }}</option>@endforeach</select></label>
                <label>{{ __('Day of the month') }} <input type="number" name="day_of_month" min="1" max="31" value="1"></label>
                <label>{{ __('Time') }} <input type="time" name="time_of_day" value="08:00" required></label>
                <label>{{ __('Format') }} <select name="format">@foreach(['pdf' => 'PDF', 'xlsx' => 'XLSX', 'docx' => 'Word', 'csv' => 'CSV'] as $format => $label)<option value="{{ $format }}">{{ $label }}</option>@endforeach</select></label>
            </div>
            <fieldset><legend>{{ __('Send to') }}</legend>
                @foreach($accounts as $account)<label style="display: block"><input type="checkbox" name="recipients[]" value="{{ $account->id }}" @checked($account->id === auth()->id())> {{ $account->name }} <span class="meta">{{ $account->email }}</span></label>@endforeach
            </fieldset>
            <button type="submit">{{ __('Add the schedule') }}</button>
        </form>

        <h2>{{ __('Share links') }}</h2>
        <p class="meta">{{ __('A share link opens the report to anyone who has it, without signing in, until it expires or is revoked. It shows whatever the report holds, published or not.') }}</p>
        @if($shares->isEmpty())<p>{{ __('None.') }}</p>@else
            <ul>@foreach($shares as $share)
                <li>{{ __('Made :date, expires :expires, opened :count times', ['date' => $share->created_at->format('Y-m-d'), 'expires' => $share->expires_at->format('Y-m-d'), 'count' => $share->opened]) }}
                    @if($share->isOpen())<form method="post" action="{{ route('reports.builder.shares.revoke', [$report, $share]) }}" style="display: inline">@csrf<button type="submit">{{ __('Revoke') }}</button></form>
                    @else <span class="meta">{{ $share->revoked_at ? __('revoked') : __('expired') }}</span>@endif</li>
            @endforeach</ul>
        @endif
        <form method="post" action="{{ route('reports.builder.shares.store', $report) }}">
            @csrf
            <label>{{ __('Open for (days)') }} <input type="number" name="days" min="1" max="{{ \App\Reports\ReportDelivery::SHARE_DAYS_MAX }}" value="30" required></label>
            <button type="submit">{{ __('Make a share link') }}</button>
        </form>

        <h2>{{ __('Versions') }}</h2>
        @if($versions->isEmpty())<p>{{ __('None.') }}</p>@else
            <ul>@foreach($versions as $version)
                <li>{{ __('Version :number', ['number' => $version->number]) }}, {{ $version->created_at->format('Y-m-d H:i') }}, {{ $version->author?->name }}@if($version->summary): {{ $version->summary }}@endif
                    <form method="post" action="{{ route('reports.builder.versions.restore', [$report, $version]) }}" style="display: inline">@csrf<button type="submit">{{ __('Restore') }}</button></form></li>
            @endforeach</ul>
        @endif
        <form method="post" action="{{ route('reports.builder.versions.store', $report) }}">
            @csrf
            <label>{{ __('Keep this as a version, noting') }} <input name="summary" maxlength="500"></label>
            <button type="submit">{{ __('Keep') }}</button>
        </form>
    @endif
@endsection
