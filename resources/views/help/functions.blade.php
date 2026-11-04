{{-- OpenRiC Workspace - the code reference: its catalogues (Heratio's functions docs; roadmap phase 20d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Code reference'))
@section('content')
    <h1>{{ __('Code reference') }}</h1>
    <p>{{ __('What the Workspace is made of, read from its code: each class, template, route, command and vocabulary with what its own comment says and where it is. For who administers the Workspace and for developers; the Help Center explains how to use it.') }} <a href="{{ route('help.index') }}">{{ __('Help Center') }}</a></p>
    <ul>@foreach($catalogues as $kind => $c)<li><a href="{{ route('functions-docs.kind', $kind) }}">{{ __($c['title']) }}</a> <span class="meta">{{ trans_choice(':count entry|:count entries', $c['entries'], ['count' => $c['entries']]) }}</span></li>@endforeach</ul>
@endsection
