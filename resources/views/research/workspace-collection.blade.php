{{-- OpenRiC Workspace - a collection opened through a research workspace (roadmap phase 19d): what the member may read of it. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $collection->name)
@section('content')
    @php($back ??= [route('research.workspaces.show', $resource->workspace), $resource->workspace->name])
    <p><a href="{{ $back[0] }}">{{ $back[1] }}</a></p>
    <h1>{{ $collection->name }}</h1>
    @if($collection->description)<p>{{ $collection->description }}</p>@endif
    @forelse($items as $i)<p>@if($i['url'])<a href="{{ $i['url'] }}">{{ $i['title'] }}</a>@else{{ $i['title'] }}@endif @if($i['item']->reference_code)<span class="meta">{{ $i['item']->reference_code }}</span>@endif @if($i['item']->notes)<br>{{ $i['item']->notes }}@endif</p>
    @empty<p>{{ __('Nothing here that you may read.') }}</p>@endforelse
@endsection
