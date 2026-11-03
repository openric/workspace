{{-- OpenRiC Workspace - label templates (Heratio's ahg-label templates; roadmap phase 22a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Label templates'))
@section('content')
    <h1>{{ __('Label templates') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('The sheets labels are printed on. Labels are printed from a record (for it, or for what is inside it) and from a container. Barcodes and QR codes are drawn by the Workspace; nothing is sent to an outside service.') }}</p>
    @foreach($templates as $t)
        <details><summary>{{ $t->name }} · {{ $t->columns }} x {{ $t->rows }} · {{ $t->pageSize->label(\App\Support\Vocab::languages()) }}@if($t->is_default) · <strong>{{ __('default') }}</strong>@endif</summary>
            <form method="post" action="{{ route('labels.templates.update', $t) }}">@csrf @include('labels._template-fields', ['t' => $t, 'x' => 't'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
    @endforeach
    <details><summary>{{ __('Add a template') }}</summary><form method="post" action="{{ route('labels.templates.store') }}">@csrf @include('labels._template-fields', ['t' => null, 'x' => 'tn'])<button type="submit">{{ __('Add') }}</button></form></details>
@endsection
