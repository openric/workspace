{{-- OpenRiC Workspace - a training certificate, checked by its number (roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Certificate :number', ['number' => $certificate->certificate_no]))
@section('content')
    <h1>{{ __('Certificate :number', ['number' => $certificate->certificate_no]) }}</h1>
    <p>{{ __(':name completed :course with :score% on :date.', ['name' => $certificate->enrolment->learner_name, 'course' => $certificate->enrolment->course->title,
        'score' => $certificate->score, 'date' => $certificate->issued_at->timezone(config('workspace.timezone'))->format('j F Y')]) }}</p>
    <p class="meta">{{ \App\Models\Institution::find($certificate->enrolment->course->institution_id)?->name }}</p>
@endsection
