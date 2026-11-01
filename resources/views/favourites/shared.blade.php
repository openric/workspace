{{-- OpenRiC Workspace - a folder of favourites opened by its link (Heratio's shared folder view; roadmap phase 19a). Shows only what the public may read. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $folder->name)
@section('content')
    <h1>{{ $folder->name }}</h1>
    @if($folder->description)<p>{{ $folder->description }}</p>@endif
    @if($items->isEmpty())<p>{{ __('Nothing here that the public may see.') }}</p>@else
        <ul>@foreach($items as $i)<li><a href="{{ $i['url'] }}">{{ $i['title'] }}</a>@if($i['reference']) <span class="meta">{{ $i['reference'] }}</span>@endif</li>@endforeach</ul>
    @endif
@endsection
