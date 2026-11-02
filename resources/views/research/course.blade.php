{{-- OpenRiC Workspace - a training course: modules, the assessment, enrolments (Heratio's training pages; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $course->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($done = $mine ? $mine->progress->pluck('module_id')->all() : [])
    <p><a href="{{ route('research.teaching') }}">{{ __('Lectures and training') }}</a></p>
    <h1>{{ $course->title }}</h1>
    @include('research._head')
    <p class="meta">{{ $course->status->label($lang) }}@if($course->audience) · {{ $course->audience }}@endif @if($course->language) · {{ $course->language }}@endif · {{ __('Pass mark :mark%', ['mark' => $course->assessment?->pass_mark ?? $course->pass_mark]) }}</p>
    @if($course->description)<p style="white-space: pre-wrap">{{ $course->description }}</p>@endif
    @if($mine)<p>{{ __('You are enrolled') }}: {{ $mine->status->label($lang) }}@if($mine->score !== null) · {{ __('best score :score%', ['score' => $mine->score]) }}@endif
        @if($mine->certificate) · {{ __('Certificate') }} <a href="{{ route('research.certificates.show', $mine->certificate->certificate_no) }}">{{ $mine->certificate->certificate_no }}</a>@endif</p>
    @elseif($course->status->notation === 'published')<form method="post" action="{{ route('research.courses.step', [$course, 'enrol']) }}">@csrf<button type="submit">{{ __('Enrol') }}</button></form>@endif

    <h2>{{ __('Modules') }}</h2>
    @forelse($course->modules as $m)
        <section>
            <h3>{{ $m->title }}@if(in_array($m->id, $done, true)) <span class="meta">({{ __('done') }})</span>@endif</h3>
            @if($m->lecture)<p>{{ __('Lecture') }}: <a href="{{ route('research.lectures.show', $m->lecture) }}">{{ $m->lecture->title }}</a></p>@endif
            {!! \App\Research\Teaching::html($m->body) !!}
            @if($mine)<form method="post" action="{{ route('research.enrolments.step', [$mine, in_array($m->id, $done, true) ? 'undo' : 'done', $m->id]) }}">@csrf<button type="submit">{{ in_array($m->id, $done, true) ? __('Mark not done') : __('Mark done') }}</button></form>@endif
            @if($mayEdit)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.courses.step', [$course, 'module', $m->id]) }}">@csrf @include('research._module-fields', ['m' => $m, 'x' => 'm'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.courses.step', [$course, 'remove-module', $m->id]) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form></details>@endif
        </section>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    @if($mayEdit)<details><summary>{{ __('Add a module') }}</summary><form method="post" action="{{ route('research.courses.step', [$course, 'module']) }}">@csrf @include('research._module-fields', ['m' => null, 'x' => 'mn'])<button type="submit">{{ __('Add') }}</button></form></details>@endif

    <h2>{{ __('Assessment') }}</h2>
    @if($course->assessment && $course->assessment->questions)
        @if(session('result'))<p role="status">{{ __(':score%', ['score' => session('result')['score']]) }}@if(! session('result')['modules_done']) · {{ __('Mark every module done to complete the course.') }}@endif @if(session('result')['certificate']) · {{ __('Certificate') }} {{ session('result')['certificate'] }}@endif</p>@endif
        @if($mine)
            <form method="post" action="{{ route('research.enrolments.step', [$mine, 'submit']) }}">@csrf
                @foreach($course->assessment->questions as $i => $q)
                    <fieldset><legend>{{ $i + 1 }}. {{ $q['question'] }}</legend>
                        @foreach($q['options'] as $o => $option)<label><input type="radio" name="answers[{{ $i }}]" value="{{ $o }}" required> {{ $option }}</label><br>@endforeach</fieldset>
                @endforeach
                <button type="submit">{{ __('Hand in') }}</button></form>
        @else
            <p>{{ trans_choice(':count question|:count questions', count($course->assessment->questions), ['count' => count($course->assessment->questions)]) }}</p>
        @endif
    @else
        <p>{{ __('None yet.') }}</p>
    @endif
    @if($mayEdit)
        <details><summary>{{ __('Change the assessment') }}</summary><form method="post" action="{{ route('research.courses.step', [$course, 'assessment']) }}">@csrf
            <label for="a-title">{{ __('Title') }}</label> <input id="a-title" name="title" size="30" value="{{ $course->assessment?->title }}"> <label for="a-pass">{{ __('Pass mark (%), if not the course\'s') }}</label> <input id="a-pass" name="pass_mark" type="number" min="0" max="100" style="width: 5rem" value="{{ $course->assessment?->pass_mark }}">
            @foreach([...($course->assessment?->questions ?? []), ['question' => '', 'options' => [], 'answer' => 0]] as $i => $q)
                <fieldset><legend>{{ __('Question :n', ['n' => $i + 1]) }}</legend>
                    <input name="questions[{{ $i }}][question]" size="70" value="{{ $q['question'] }}" aria-label="{{ __('Question :n', ['n' => $i + 1]) }}"><br>
                    @for($o = 0; $o < max(4, count($q['options'])); $o++)<label><input type="radio" name="questions[{{ $i }}][answer]" value="{{ $o }}" @checked(($q['answer'] ?? 0) === $o)> {{ __('right') }}</label> <input name="questions[{{ $i }}][options][]" size="40" value="{{ $q['options'][$o] ?? '' }}" aria-label="{{ __('Option :n', ['n' => $o + 1]) }}"><br>@endfor
                </fieldset>
            @endforeach
            <button type="submit">{{ __('Save') }}</button></form></details>
        <h2>{{ __('Learners') }}</h2>
        <ul>@forelse($course->enrolments as $e)<li>{{ $e->learner_name }}@if($e->learner_email) ({{ $e->learner_email }})@endif · {{ $e->status->label($lang) }}@if($e->score !== null) · {{ $e->score }}%@endif @if($e->certificate) · {{ $e->certificate->certificate_no }}@endif
            <form method="post" action="{{ route('research.courses.step', [$course, 'remove-enrolment', $e->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
        <form method="post" action="{{ route('research.courses.step', [$course, 'enrol-learner']) }}">@csrf<label for="el-name">{{ __('Enrol a learner') }}</label> <input id="el-name" name="learner_name" required size="25" placeholder="{{ __('Name') }}">
            <label for="el-mail">{{ __('E-mail address') }}</label> <input id="el-mail" name="learner_email" type="email" size="25"> <button type="submit">{{ __('Enrol') }}</button></form>
        <details><summary>{{ __('Change the course') }}</summary><form method="post" action="{{ route('research.courses.save', $course) }}">@csrf
            <label for="ce-title">{{ __('Title') }}</label> <input id="ce-title" name="title" required size="40" value="{{ $course->title }}">
            <label for="ce-status">{{ __('Status') }}</label> @include('projects._select', ['id' => 'ce-status', 'name' => 'status', 'choices' => $statuses, 'current' => $course->status->notation, 'default' => 'draft'])<br>
            <label for="ce-aud">{{ __('Audience') }}</label> <input id="ce-aud" name="audience" size="25" value="{{ $course->audience }}"> <label for="ce-lang">{{ __('Language') }}</label> <input id="ce-lang" name="language" size="8" value="{{ $course->language }}">
            <label for="ce-pass">{{ __('Pass mark (%)') }}</label> <input id="ce-pass" name="pass_mark" type="number" min="0" max="100" style="width: 5rem" value="{{ $course->pass_mark }}"><br>
            <label for="ce-desc">{{ __('Description') }}</label><br><textarea id="ce-desc" name="description" rows="3" cols="80">{{ $course->description }}</textarea><br><button type="submit">{{ __('Save') }}</button></form>
            <form method="post" action="{{ route('research.courses.step', [$course, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the course') }}</button></form></details>
    @endif
@endsection
