{{-- OpenRiC Workspace - a DOI's landing page: what was registered for it at DataCite (roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $doi->metadata['titles'][0]['title'] ?? $doi->doi)
@section('content')
    @php($m = $doi->metadata)
    <h1>{{ $m['titles'][0]['title'] ?? $doi->doi }}</h1>
    <p>DOI: <a href="https://doi.org/{{ $doi->doi }}">https://doi.org/{{ $doi->doi }}</a>@if($doi->test) ({{ __('DataCite test service') }})@endif</p>
    <dl>
        <dt>{{ __('Creators') }}</dt><dd>@foreach($m['creators'] ?? [] as $c){{ $c['name'] }}@if(isset($c['nameIdentifiers'][0])) (<a href="{{ $c['nameIdentifiers'][0]['nameIdentifier'] }}">ORCID</a>)@endif @if(! $loop->last); @endif @endforeach</dd>
        <dt>{{ __('Publisher') }}</dt><dd>{{ $m['publisher']['name'] ?? $institution?->name }}</dd>
        <dt>{{ __('Year') }}</dt><dd>{{ $m['publicationYear'] ?? '' }}</dd>
        <dt>{{ __('Type') }}</dt><dd>{{ $m['types']['resourceType'] ?? $m['types']['resourceTypeGeneral'] ?? '' }}</dd>
        @if(! empty($m['descriptions'][0]['description']))<dt>{{ __('Abstract') }}</dt><dd style="white-space: pre-wrap">{{ $m['descriptions'][0]['description'] }}</dd>@endif
        @foreach($m['relatedIdentifiers'] ?? [] as $r)<dt>{{ __('Part of') }}</dt><dd><a href="https://doi.org/{{ $r['relatedIdentifier'] }}">{{ $r['relatedIdentifier'] }}</a></dd>@endforeach
    </dl>
@endsection
