{{-- OpenRiC Workspace - describe a repository. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Describe repository')
@section('content')
    <p><a href="{{ route('repositories.show', $repository) }}">Back to the repository</a></p>
    <h1>Describe {{ $repository->described('authorised-name', [$language, 'en']) }}</h1>
    <p>Values in <strong>{{ $language }}</strong>. Fill in what you know; empty fields are left out. A value you change goes back to draft.</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('repositories.update', $repository) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">Language of these values (BCP 47)</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="identifier">Identifier (ISDIAH 5.1.1)</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="identifier" name="identifier" value="{{ old('identifier', $repository->identifier) }}">
        <label style="font-weight: normal"><input type="hidden" name="complete_reference_codes" value="0"><input type="checkbox" name="complete_reference_codes" value="1" style="width: auto" @checked(old('complete_reference_codes', $repository->complete_reference_codes))> Each unit it holds carries its complete reference code, so codes are not built from the units above it</label>
        @include('description._fields', ['subject' => $repository, 'standard' => \App\Description\Elements::ISDIAH, 'language' => $language])
        <h2>Control (ISDIAH 5.6)</h2>
        <label for="description_identifier">Description identifier (5.6.1)</label> @include('description._ric', ['term' => null])
        <input id="description_identifier" name="description_identifier" value="{{ old('description_identifier', $repository->description_identifier) }}">
        <label for="description_status">Status (5.6.4)</label> @include('description._ric', ['term' => null])
        <select id="description_status" name="description_status" style="font: inherit; padding: .5rem">
            <option value="">Not set</option>
            @foreach($statuses as $status)<option value="{{ $status->iri }}" @selected(old('description_status', $repository->descriptionStatus?->iri) === $status->iri)>{{ $status->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        <label for="description_detail">Level of detail (5.6.5)</label> @include('description._ric', ['term' => null])
        <select id="description_detail" name="description_detail" style="font: inherit; padding: .5rem">
            <option value="">Not set</option>
            @foreach($details as $detail)<option value="{{ $detail->iri }}" @selected(old('description_detail', $repository->descriptionDetail?->iri) === $detail->iri)>{{ $detail->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        <button type="submit">Save</button>
    </form>
@endsection
