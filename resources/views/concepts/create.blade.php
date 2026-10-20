{{-- OpenRiC Workspace - new concept. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'New concept')
@section('content')
    <p><a href="{{ route('vocabularies.show', $vocabulary) }}">{{ $vocabulary->title }}</a></p>
    <h1>New concept in {{ $vocabulary->title }}</h1>
    <form method="post" action="{{ route('vocabularies.concepts.store', $vocabulary) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        @include('concepts._fields', ['concept' => null, 'language' => null])
        <button type="submit">Add concept</button>
    </form>
@endsection
