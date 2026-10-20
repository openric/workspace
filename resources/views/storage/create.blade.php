{{-- OpenRiC Workspace - new container. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'New container')
@section('content')
    <p><a href="{{ route('containers.index') }}">Back</a></p>
    <h1>New container @include('description._ric', ['term' => 'openricx:Container'])</h1>
    <form method="post" action="{{ route('containers.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">Name</label> @include('description._ric', ['term' => 'rico:Name'])
        <input id="name" name="name" required maxlength="1024" value="{{ old('name') }}" aria-describedby="name-help">
        <small id="name-help">What its label says, such as "Box 12" or "Map drawer 3".</small>
        <label for="language">Language of the name (BCP 47, e.g. en, af)</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        @include('storage._type-field', ['types' => $types, 'current' => null])
        <label for="identifier">Identifier</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="identifier" name="identifier" maxlength="255" value="{{ old('identifier') }}">
        <label for="barcode">Barcode</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="barcode" name="barcode" maxlength="255" value="{{ old('barcode') }}" aria-describedby="barcode-help">
        <small id="barcode-help">One container per barcode, so scanning it finds this one.</small>
        @include('storage._levels', ['levels' => $levels, 'current' => $current])
        <label for="place">Or choose a storage place already there</label>
        <select id="place" name="place" style="font: inherit; padding: .5rem">
            <option value="">Nowhere yet</option>
            @foreach($places as $p)<option value="{{ $p->id }}" @selected(old('place') === $p->id)>{{ $p->path }}</option>@endforeach
        </select>
        <button type="submit">Create container</button>
    </form>
@endsection
