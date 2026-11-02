{{-- OpenRiC Workspace - research documents and their templates (Heratio's document templates page; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Research documents'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($lines = fn ($t) => collect($t?->fields ?? [])->map(fn ($f) => $f['label'].' | '.$f['type'].($f['required'] ? ' | required' : ''))->implode("\n"))
    <h1>{{ __('Research documents') }}</h1>
    @include('research._head')
    <h2>{{ __('Your documents') }}</h2>
    <ul>@forelse($documents as $d)<li><a href="{{ route('research.documents.edit', [$d->template, $d]) }}">{{ $d->title }}</a> · {{ $d->template->name }}@if($d->project) · {{ $d->project->title }}@endif · {{ $d->updated_at->format('j M Y') }}</li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    <h2>{{ __('Templates') }}</h2>
    @forelse($templates as $t)
        <p><a href="{{ route('research.documents.edit', $t) }}">{{ __('Fill in :name', ['name' => $t->name]) }}</a> · {{ $t->documentType->label($lang) }}@if($t->description) · {{ $t->description }}@endif</p>
        @if($mayRun)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.documents.templates.save', $t) }}">@csrf @include('research._template-fields', ['t' => $t, 'x' => 't'.$loop->index, 'fieldLines' => $lines($t)])<button type="submit">{{ __('Save') }}</button></form></details>@endif
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    @if($mayRun)<details><summary>{{ __('Add a template') }}</summary><form method="post" action="{{ route('research.documents.templates.save') }}">@csrf @include('research._template-fields', ['t' => null, 'x' => 'tn', 'fieldLines' => ''])<button type="submit">{{ __('Add') }}</button></form></details>@endif
@endsection
