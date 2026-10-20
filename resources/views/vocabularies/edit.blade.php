{{-- OpenRiC Workspace - rename or describe a vocabulary. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Edit vocabulary')
@section('content')
    <p><a href="{{ route('vocabularies.show', $vocabulary) }}">Back to the vocabulary</a></p>
    <h1>Edit {{ $vocabulary->title }}</h1>
    <form method="post" action="{{ route('vocabularies.update', $vocabulary) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        @include('vocabularies._fields', ['vocabulary' => $vocabulary])
        <button type="submit">Save</button>
    </form>
@endsection
