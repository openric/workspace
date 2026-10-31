{{-- OpenRiC Workspace - one record's IIIF validation runs (roadmap phase 14b, ADR 0014), after PSIS's validation history. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('IIIF validation of :name', ['name' => \App\Relations\EntityKinds::label($unit)]))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('iiif-validation.index') }}">{{ __('IIIF validation') }}</a> · <a href="{{ route('records.show', $unit) }}">{{ \App\Relations\EntityKinds::label($unit) }}</a></p>
    <h1>{{ __('IIIF validation of :name', ['name' => \App\Relations\EntityKinds::label($unit)]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('iiif-validation.validate', $unit) }}">@csrf<button type="submit">{{ __('Check now') }}</button></form>
    @forelse($runs as $results)
        <h2>{{ $results->first()->validated_at->format('Y-m-d H:i') }}</h2>
        <table>
            <thead><tr><th scope="col">{{ __('Check') }}</th><th scope="col">{{ __('Outcome') }}</th><th scope="col">{{ __('Details') }}</th></tr></thead>
            <tbody>@foreach($results as $r)<tr><td>{{ $r->check->label($lang) }}</td><td>{{ $r->status->label($lang) }}</td><td>{{ $r->details }}</td></tr>@endforeach</tbody>
        </table>
    @empty<p>{{ __('Not checked yet.') }}</p>@endforelse
@endsection
