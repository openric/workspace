{{-- OpenRiC Workspace - a project's snapshots (Heratio's snapshots; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Snapshots').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('analysis._nav')
    <h1>{{ __('Snapshots') }}</h1>
    <p class="meta">{{ __('A snapshot keeps the project\'s records as they are now, with a checksum of each, to cite and to compare later.') }}</p>
    @forelse($snapshots as $s)<p><a href="{{ route('research.analysis.snapshot', $s) }}">{{ $s->title }}</a> · {{ $s->status->label($lang) }} · {{ trans_choice(':count record|:count records', $s->item_count, ['count' => $s->item_count]) }} · <span class="meta">{{ $s->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }} · {{ $s->user?->name }}</span></p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<form method="post" action="{{ route('research.analysis.snapshots.step', [$project, 'take']) }}">@csrf<label for="sn-title">{{ __('Title') }}</label> <input id="sn-title" name="title" size="40">
        <label for="sn-desc">{{ __('Description') }}</label> <input id="sn-desc" name="description" size="40"> <button type="submit">{{ __('Take a snapshot') }}</button></form>@endif
@endsection
