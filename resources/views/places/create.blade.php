{{-- OpenRiC Workspace - new place. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'New place')
@section('content')
    <p><a href="{{ $parent ? route('places.show', $parent) : route('places.index') }}">Back</a></p>
    <h1>New place{{ $parent ? ' in '.$parent->described('place-name', ['en']) : '' }}</h1>
    <form method="post" action="{{ route('places.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        @if($parent)<input type="hidden" name="parent" value="{{ $parent->id }}">@endif
        <label for="name">Authorised name</label>
        <input id="name" name="name" required maxlength="1024" value="{{ old('name') }}">
        <label for="language">Language of the name (BCP 47, e.g. en, af)</label>
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        <button type="submit">Create place</button>
    </form>
@endsection
