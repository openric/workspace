{{-- OpenRiC Workspace - a research report to print (Heratio's renderReportHtml; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $report->title)
@section('content')
    <p class="no-print"><a href="{{ route('research.reports.show', $report) }}">{{ __('Back to the report') }}</a> · <button type="button" onclick="window.print()">{{ __('Print') }}</button></p>
    @foreach($report->sections as $s)<section>@include('analysis._section-body')</section>@endforeach
@endsection
