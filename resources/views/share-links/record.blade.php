{{-- OpenRiC Workspace - a record opened by a share link (roadmap phase 19a, ADR 0017), after Heratio's share link recipient view: what its issuer may read of it, and its files when they are allowed. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $view->label($unit) ?? __('Shared record'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p class="meta">{{ __('Shared with you by :institution until :date.', ['institution' => \App\Models\Institution::find($unit->institution_id)?->name, 'date' => $link->expires_at->format('j F Y')]) }}</p>
    <h1>{{ $view->label($unit) }}</h1>
    <dl>
        @if($code = $unit->referenceCode())<dt>{{ __('Reference code') }}</dt><dd>{{ $code }}</dd>@endif
        @if($unit->level)<dt>{{ __('Level of description') }}</dt><dd>{{ $unit->level->label($lang) }}</dd>@endif
        @foreach($unit->dates as $d)<dt>{{ __('Date') }}</dt><dd>{{ $d->display() }}</dd>@endforeach
        @foreach($elements as $element)
            @continue($element->notation === 'title')
            @foreach($view->values($unit, $element->notation) as $value)<dt>{{ $element->label($lang) }}</dt><dd lang="{{ $value->language }}" style="white-space: pre-line">{{ $value->value }}</dd>@endforeach
        @endforeach
    </dl>
    @if($files->isNotEmpty())
        <h2>{{ __('Files') }}</h2>
        <ul>@foreach($files as $f)<li><a href="{{ route('share-links.file', [$token, $f]) }}">{{ $f->original_name }}</a> <span class="meta">{{ $f->use->label($lang) }} · {{ number_format($f->size_bytes / 1024, 0) }} KB</span></li>@endforeach</ul>
    @endif
@endsection
