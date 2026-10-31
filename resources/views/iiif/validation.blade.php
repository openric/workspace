{{-- OpenRiC Workspace - the IIIF validation dashboard (roadmap phase 14b, ADR 0014), after PSIS's: the latest outcome of every record checked and recent failures. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('IIIF validation'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('IIIF validation') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Each record\'s manifest is checked before it is relied on: its title, something to show, the files on disk, the sizes and lengths a viewer needs, a thumbnail, rights, the holder, and whether it is published.') }}</p>
    <p><strong>{{ __('Records checked') }}:</strong> {{ $dashboard['records'] }} · {{ __('Passed') }}: {{ $dashboard['passed'] }} · {{ __('With warnings') }}: {{ $dashboard['warning'] }} · {{ __('Failed') }}: {{ $dashboard['failed'] }}</p>
    <form method="post" action="{{ route('iiif-validation.all') }}">@csrf<button type="submit">{{ __('Check every record with a file') }}</button></form>
    <h2>{{ __('Recent failures') }}</h2>
    @if($dashboard['failures']->isEmpty())<p>{{ __('None.') }}</p>@else
    <table>
        <thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Check') }}</th><th scope="col">{{ __('Details') }}</th><th scope="col">{{ __('When') }}</th></tr></thead>
        <tbody>@foreach($dashboard['failures'] as $r)
            <tr><td><a href="{{ route('iiif-validation.record', $r->record) }}">{{ \App\Relations\EntityKinds::label($r->record) }}</a></td><td>{{ $r->check->label($lang) }}</td><td>{{ $r->details }}</td><td>{{ $r->validated_at->format('Y-m-d H:i') }}</td></tr>
        @endforeach</tbody>
    </table>@endif
@endsection
