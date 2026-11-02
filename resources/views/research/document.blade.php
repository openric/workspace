{{-- OpenRiC Workspace - a research document, filled in from its template, and printed (roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $document?->title ?? $template->name)
@section('content')
    <p class="no-print"><a href="{{ route('research.documents') }}">{{ __('Research documents') }}</a>@if($document) · <a href="#" onclick="window.print(); return false">{{ __('Print') }}</a>@endif</p>
    <h1>{{ $document?->title ?? $template->name }}</h1>
    @include('research._head')
    @if($document)
        <dl>@foreach($template->fields as $f)<dt>{{ $f['label'] }}</dt><dd style="white-space: pre-wrap">{{ $document->values[$f['key']] ?? '' }}</dd>@endforeach</dl>
    @endif
    <form method="post" action="{{ route('research.documents.save', [$template, $document]) }}" class="no-print">@csrf
        <label for="d-title">{{ __('Title') }}</label> <input id="d-title" name="title" size="50" value="{{ $document?->title ?? $template->name }}">
        <label for="d-project">{{ __('Project') }}</label> <select id="d-project" name="project_id"><option value="">{{ __('None') }}</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected($document?->project_id === $p->id)>{{ $p->title }}</option>@endforeach</select><br>
        @foreach($template->fields as $f)
            <label for="d-{{ $f['key'] }}">{{ $f['label'] }}@if($f['required']) *@endif</label><br>
            @if($f['type'] === 'textarea')<textarea id="d-{{ $f['key'] }}" name="values[{{ $f['key'] }}]" rows="4" cols="80" @required($f['required'])>{{ $document?->values[$f['key']] ?? '' }}</textarea>
            @else<input id="d-{{ $f['key'] }}" name="values[{{ $f['key'] }}]" type="{{ $f['type'] === 'date' ? 'date' : 'text' }}" size="50" value="{{ $document?->values[$f['key']] ?? '' }}" @required($f['required'])>@endif<br>
        @endforeach
        <button type="submit">{{ __('Save') }}</button></form>
@endsection
