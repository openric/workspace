{{-- OpenRiC Workspace - citing a record (Heratio's cite page; roadmap phase 19c): in each style to copy, and as a file for a reference manager. Needs $unit, $styles, $files, $institution. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends(auth()->check() ? 'layouts.app' : 'layouts.public')
@section('title', __('Cite this record'))
@section('content')
    <h1>{{ __('Cite this record') }}</h1>
    <p><a href="{{ auth()->check() ? route('records.show', $unit) : route('discover.record', $unit->id) }}">{{ \App\Relations\EntityKinds::label($unit, \App\Support\Vocab::languages()) }}</a></p>
    <dl>@foreach($styles as $style => $text)<dt>{{ \App\Research\Citations::FORMATS[$style] }}</dt><dd><code style="white-space: pre-wrap">{{ $text }}</code></dd>@endforeach</dl>
    <p>{{ __('For a reference manager') }}: @foreach($files as $f => $l)<a href="{{ route('cite', [$unit->id, 'format' => $f]) }}">{{ __($l) }}</a>@if(! $loop->last) · @endif @endforeach</p>
@endsection
