{{-- OpenRiC Workspace - describe an accession (Heratio's accession edit: basic info and administrative area). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Edit accession record'))
@section('content')
    <p><a href="{{ route('accessions.show', $accession) }}">{{ __('Back to the accession') }}</a></p>
    <h1>{{ __('Edit accession record') }}: {{ $accession->heading([$language, 'en']) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft.') }}</p>
    <form method="post" action="{{ route('accessions.update', $accession) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Basic info') }}</strong></legend>
            <label for="number">{{ __('Accession number') }} (CAAIS 1.2)</label> @include('description._ric', ['term' => 'rico:Identifier'])
            <input id="number" name="number" required maxlength="255" value="{{ old('number', $accession->number()) }}">
            <label for="repository">{{ __('Repository') }} (CAAIS 1.1)</label> @include('description._ric', ['term' => null])
            <small id="repository-help">{{ __('The institution that accepts legal responsibility for the accessioned material (CAAIS 1.1).') }}</small>
            <select id="repository" name="repository" aria-describedby="repository-help" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($repositories as $r)<option value="{{ $r->id }}" @selected(old('repository', $accession->repository_id) === $r->id)>{{ $r->described('authorised-name', [app()->getLocale(), 'en']) }}</option>@endforeach
            </select>
            @foreach(['acquisition_type' => ['Acquisition type', 'CAAIS 1.4'], 'resource_type' => ['Resource type', null], 'processing_status' => ['Processing status', 'CAAIS 1.6'], 'processing_priority' => ['Processing priority', null]] as $field => [$label, $number])
                <label for="{{ $field }}">{{ __($label) }}@if($number) ({{ $number }})@endif</label> @include('description._ric', ['term' => null])
                <select id="{{ $field }}" name="{{ $field }}" style="font: inherit; padding: .5rem">
                    <option value="">{{ __('Not set') }}</option>
                    @foreach($terms[$field] as $t)<option value="{{ $t->notation }}" @selected(old($field, $accession->{\Illuminate\Support\Str::camel($field)}?->notation) === $t->notation)>{{ $t->label(\App\Support\Vocab::languages()) }}</option>@endforeach
                </select>
            @endforeach
        </fieldset>
        @include('description._fields', ['subject' => $accession, 'standard' => \App\Description\Elements::ACCESSION, 'language' => $language])
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
