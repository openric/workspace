{{-- OpenRiC Workspace - a project's writing studio (Heratio's writing studio; roadmap phase 19g). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Writing studio').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('studios._nav')
    <h1>{{ __('Writing studio') }}</h1>
    @forelse($docs as $d)<p><a href="{{ route('research.studios.doc', $d) }}">{{ $d->title }}</a> · {{ $d->docType->label($lang) }} · {{ $d->status->label($lang) }} · {{ trans_choice(':count section|:count sections', $d->sections_count, ['count' => $d->sections_count]) }}</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<h2>{{ __('New document') }}</h2><form method="post" action="{{ route('research.studios.writing.step', [$project, 'doc']) }}">@csrf
        <label for="wd-title">{{ __('Title') }}</label> <input id="wd-title" name="title" required size="40">
        <label for="wd-type">{{ __('Kind') }}</label> <select id="wd-type" name="doc_type">@foreach($types as $t)<option value="{{ $t->notation }}">{{ $t->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Create') }}</button></form>@endif
@endsection
