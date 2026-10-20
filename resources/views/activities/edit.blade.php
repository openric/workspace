{{-- OpenRiC Workspace - describe an activity. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Describe activity')
@section('content')
    <p><a href="{{ route('activities.show', $activity) }}">Back to the activity</a></p>
    <h1>Describe {{ $activity->described('activity-name', [$language, 'en']) }}</h1>
    <p>Values in <strong>{{ $language }}</strong>. Fill in what you know; empty fields are left out. A value you change goes back to draft.</p>
    <form method="post" action="{{ route('activities.update', $activity) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">Language of these values (BCP 47)</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        @include('activities._type-field', ['types' => $types, 'current' => $activity->activity_type_id])
        @include('description._fields', ['subject' => $activity, 'standard' => \App\Description\Elements::ACTIVITY, 'language' => $language])
        <button type="submit">Save</button>
    </form>
@endsection
