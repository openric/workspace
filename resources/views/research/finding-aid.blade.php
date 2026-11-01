{{-- OpenRiC Workspace - the finding aid of a collection, to print (Heratio's getCollectionFindingAidData; roadmap phase 19d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Finding aid').': '.$collection->name)
@section('content')
    <p class="no-print"><a href="{{ route('research.collections.show', $collection) }}">{{ $collection->name }}</a> · <button type="button" onclick="window.print()">{{ __('Print') }}</button></p>
    <h1>{{ $collection->name }}</h1>
    @if($collection->description)<p>{{ $collection->description }}</p>@endif
    <p class="meta">{{ __('Compiled by :name on :date', ['name' => auth()->user()->name, 'date' => now(config('workspace.timezone'))->format('j F Y')]) }}</p>
    @forelse($records as $r)
        <section style="border-top: 1px solid var(--line); padding: .4rem 0">
            <h2 style="font-size: 1.05rem">@if($r['identifier']){{ $r['identifier'] }} · @endif{{ $r['title'] }}</h2>
            <p>@if($r['date']){{ __('Dates') }}: {{ $r['date'] }}<br>@endif @if($r['extent']){{ __('Extent') }}: {{ $r['extent'] }}<br>@endif @if($r['repository']){{ __('Repository') }}: {{ $r['repository'] }}<br>@endif <span class="meta">{{ $r['url'] }}</span></p>
            @if($r['scope'])<p>{{ $r['scope'] }}</p>@endif
            @if($r['notes'])<p><em>{{ __('Notes') }}:</em> {{ $r['notes'] }}</p>@endif
        </section>
    @empty<p>{{ __('The collection holds no record you may read.') }}</p>@endforelse
@endsection
