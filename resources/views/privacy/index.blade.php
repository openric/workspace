{{-- OpenRiC Workspace - privacy: the POPIA report and the registers (roadmap phase 18a, ADR 0016), after Heratio's privacy dashboard and report. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Privacy'))
@section('content')
    <h1>{{ __('Privacy') }}</h1>
    <h2>{{ __('POPIA report') }}</h2>
    <p class="meta">{{ __(':institution, :date', ['institution' => $institution->name, 'date' => now(config('workspace.timezone'))->format('j F Y')]) }}</p>
    <table><thead><tr><th scope="col">{{ __('Check') }}</th><th scope="col">{{ __('Holds') }}</th><th scope="col">{{ __('Details') }}</th></tr></thead>
        <tbody>@foreach($report as $r)<tr><td>{{ __($r['check']) }}</td><td>{{ $r['ok'] ? __('Yes') : __('No') }}</td><td>{{ $r['detail'] }}</td></tr>@endforeach</tbody></table>
    <h2>{{ __('Registers') }}</h2>
    <ul>@foreach(\App\Privacy\Registers::ALL as $key => $d)@continue(($d['pack'] ?? null) && ! $institution->usesPack($d['pack']))<li><a href="{{ route('privacy.register', $key) }}">{{ __($d['title']) }}</a> ({{ $counts[$key] }})</li>@endforeach
        <li><a href="{{ route('privacy.screening') }}">{{ __('Personal information in the catalogue: scans, redaction and file screening') }}</a></li>
        <li><a href="{{ route('privacy.controls') }}">{{ __('Jurisdictions and compliance controls') }}</a></li></ul>
@endsection
