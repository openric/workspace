{{-- OpenRiC Workspace - a collection opened by its link (roadmap phase 19d). Shows only what the public may read. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $collection->name)
@section('content')
    <h1>{{ $collection->name }}</h1>
    @if($collection->description)<p>{{ $collection->description }}</p>@endif
    @if($items->isEmpty())<p>{{ __('Nothing here that the public may see.') }}</p>@else
        <ul>@foreach($items as $i)<li>@if($i['url'])<a href="{{ $i['url'] }}">{{ $i['title'] }}</a>@else{{ $i['title'] }}@endif @if($i['reference'])<span class="meta">{{ $i['reference'] }}</span>@endif @if($i['item']->notes)<br>{{ $i['item']->notes }}@endif</li>@endforeach</ul>
    @endif
@endsection
