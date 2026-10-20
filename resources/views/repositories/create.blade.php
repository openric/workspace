{{-- OpenRiC Workspace - new repository. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'New repository')
@section('content')
    <h1>New repository</h1>
    <form method="post" action="{{ route('repositories.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="name">Authorised form of name (ISDIAH 5.1.2)</label>
        <input id="name" name="name" required value="{{ old('name') }}">
        <label for="language">Language of the name (BCP 47, e.g. en, af)</label>
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        <label for="identifier">Identifier (ISDIAH 5.1.1, optional)</label>
        <input id="identifier" name="identifier" value="{{ old('identifier') }}">
        <button type="submit">Create repository</button>
    </form>
@endsection
