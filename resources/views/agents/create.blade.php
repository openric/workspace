{{-- OpenRiC Workspace - new authority record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'New authority record')
@section('content')
    <p><a href="{{ route('agents.index') }}">Authority records</a></p>
    <h1>New authority record</h1>
    <form method="post" action="{{ route('agents.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="type">Type of entity (ISAAR(CPF) 5.1.1)</label>
        <select id="type" name="type" required style="font: inherit; padding: .5rem">
            @foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('type', 'person') === $t->notation)>{{ $t->label(['en', 'und']) }}</option>@endforeach
        </select>
        <label for="name">Authorised form of name (5.1.2)</label>
        <input id="name" name="name" required maxlength="1024" value="{{ old('name') }}">
        <label for="language">Language of the name (BCP 47, e.g. en, af)</label>
        <input id="language" name="language" required value="{{ old('language', 'en') }}">
        <label for="identifier">Identifier (5.1.6, optional)</label>
        <input id="identifier" name="identifier" maxlength="255" value="{{ old('identifier') }}">
        <button type="submit">Create authority record</button>
    </form>
@endsection
