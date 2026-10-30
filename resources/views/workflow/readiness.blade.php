{{-- OpenRiC Workspace - is a description ready to publish: the publish gates, and what the public would see (Heratio's publish readiness, PSIS's publish simulate). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Publish readiness'))
@section('content')
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back to the description') }}</a> · <a href="{{ route('publication.edit', ['records', $record->id]) }}">{{ __('Publication') }}</a></p>
    <h1>{{ __('Publish readiness') }}: {{ $record->described('title', [app()->getLocale(), 'en']) }}</h1>
    @php($failed = collect($results)->where('status', 'failed'))
    <p><strong>{{ $failed->isEmpty() ? __('Ready to publish.') : __('Not ready: :count blocker(s).', ['count' => $failed->count()]) }}</strong></p>
    @forelse($results as $r)
        <p style="margin: .2rem 0">{{ ['passed' => __('Passed'), 'failed' => __('Failed'), 'warning' => __('Warning')][$r['status']] }}: {{ $r['name'] }}@if($r['message']) - {{ $r['message'] }}@endif</p>
    @empty
        <p>{{ __('No publish gate applies to it.') }}</p>
    @endforelse
    <h2>{{ __('What the public would see') }} ({{ $language }})</h2>
    <dl>
        <dt>{{ __('Title') }}</dt><dd>{{ $simulation['title'] ?? __('none') }}</dd>
        <dt>{{ __('Reference code') }}</dt><dd>{{ $simulation['reference'] ?? __('none') }}</dd>
        <dt>{{ __('Dates') }}</dt><dd>{{ implode('; ', $simulation['dates']) ?: __('none') }}</dd>
        <dt>{{ __('Repository') }}</dt><dd>{{ $simulation['repository'] ?? __('none') }}</dd>
        <dt>{{ __('Scope and content') }}</dt><dd style="white-space: pre-line">{{ $simulation['scope'] ?? __('none') }}</dd>
        <dt>{{ __('Conditions governing access') }}</dt><dd>{{ $simulation['access'] ?? __('none') }}</dd>
        <dt>{{ __('Files') }}</dt><dd>{{ $simulation['files'] }}</dd>
        <dt>{{ __('Rights in force') }}</dt><dd>{{ implode('; ', $simulation['rights']) ?: __('none') }}</dd>
    </dl>
    <h2>{{ __('Earlier checks') }}</h2>
    @foreach($history as $h)<p style="margin: .1rem 0">{{ $h->evaluated_at->setTimezone(config('workspace.timezone'))->format('Y-m-d H:i') }} {{ $h->rule?->name }}: {{ $h->status }}</p>@endforeach
@endsection
