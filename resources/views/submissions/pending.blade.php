{{-- OpenRiC Workspace - the submissions to review (PSIS's pending; roadmap phase 19e). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Submissions to review'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Submissions to review') }}</h1>
    <form method="get" action="{{ route('research.submissions.pending') }}"><label for="pd-status">{{ __('Status') }}</label> <select id="pd-status" name="status"><option value="">{{ __('Waiting: sent, under review or approved') }}</option>@foreach($statuses as $c)<option value="{{ $c->notation }}" @selected($status === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select> <button type="submit">{{ __('Show') }}</button></form>
    @forelse($submissions as $s)<p><a href="{{ route('research.submissions.show', $s) }}">{{ $s->title }}</a> · {{ $s->status->label($lang) }} · {{ $s->user->name }} · {{ trans_choice(':count description|:count descriptions', $s->total_items, ['count' => $s->total_items]) }}
        @if($s->submitted_at) · <span class="meta">{{ __('sent :date', ['date' => $s->submitted_at->timezone(config('workspace.timezone'))->format('j M Y')]) }}</span>@endif @if($s->reviewer) · <span class="meta">{{ $s->reviewer->name }}</span>@endif</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
@endsection
