{{-- OpenRiC Workspace - the storage places, as a tree. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Storage places')
@section('content')
    @include('storage._nav')
    <h1>Storage places @include('description._ric', ['term' => 'rico:Place'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>Buildings, strongrooms, aisles, bays and shelves, each a place of a storage place type. They are kept apart from the places records are about, and none is public unless someone publishes it.
        @if($unplaced > 0)<a href="{{ route('containers.index', ['unplaced' => 1]) }}">{{ $unplaced }} {{ $unplaced === 1 ? 'container is' : 'containers are' }} kept nowhere yet.</a>@endif</p>
    @php
    $tree = function ($parent) use (&$tree, $byParent, $storage) {
        $items = $byParent[$parent] ?? collect();
        if ($items->isEmpty()) { return ''; }
        $html = '<ul>';
        foreach ($items as $place) {
            $count = $storage->containersIn($place)->count();
            $html .= '<li><a href="'.e(route('storage.place', $place)).'">'.e($place->described('place-name', ['en'])).'</a> ('.e(mb_strtolower((string) $place->placeType?->anyLabel(['en']))).($count ? ', '.$count.' '.($count === 1 ? 'container' : 'containers') : '').')'.$tree($place->id).'</li>';
        }

        return $html.'</ul>';
    };
    @endphp
    @if($byParent->isEmpty())<p>No storage places yet.</p>@else{!! $tree('top') !!}@endif

    @if($mayCreate)
        <h2>New storage place</h2>
        <form method="post" action="{{ route('storage.places.store') }}">
            @csrf
            <label for="name">Name</label> @include('description._ric', ['term' => 'rico:PlaceName'])
            <input id="name" name="name" required maxlength="1024" value="{{ old('name') }}" aria-describedby="name-help">
            <small id="name-help">Such as "Strongroom 2" or "Shelf 4".</small>
            <label for="language">Language of the name (BCP 47)</label> @include('description._ric', ['term' => 'xml:lang'])
            <input id="language" name="language" required value="{{ old('language', 'en') }}">
            <label for="type">Storage place type</label> @include('description._ric', ['term' => 'rico:hasOrHadPlaceType'])
            <select id="type" name="type" required style="font: inherit; padding: .5rem">
                @foreach($types as $t)<option value="{{ $t->id }}" @selected(old('type') === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
            </select>
            <label for="parent">In</label> @include('description._ric', ['term' => 'rico:hasOrHadLocation'])
            <select id="parent" name="parent" style="font: inherit; padding: .5rem">
                <option value="">Nothing: it is at the top</option>
                @foreach($byParent->flatten() as $p)<option value="{{ $p->id }}" @selected(old('parent') === $p->id)>{{ $p->path }}</option>@endforeach
            </select>
            <button type="submit">Create storage place</button>
        </form>
    @endif
@endsection
