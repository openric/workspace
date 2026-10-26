{{-- OpenRiC Workspace - describe an authority record. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Describe authority record'))
@section('content')
    <p><a href="{{ route('agents.show', $agent) }}">{{ __('Back to the authority record') }}</a></p>
    <h1>{{ __('Describe :name', ['name' => $agent->described('agent-authorised-name', [$language, 'en'])]) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft. Dates, places, occupations, legal statuses and relationships are kept as dates and relations on the record\'s page; the fields here are their narrative.') }}</p>
    <form method="post" action="{{ route('agents.update', $agent) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <label for="type">{{ __('Type of entity') }} (5.1.1)</label> <small>{{ __('(each choice names its RiC-O class)') }}</small>
        <select id="type" name="type" required style="font: inherit; padding: .5rem">
            @foreach($types as $t)<option value="{{ $t->notation }}" @selected(old('type', $agent->typeNotation()) === $t->notation)>{{ $t->label(\App\Support\Vocab::languages()) }} ({{ $t->ricTerm() }})</option>@endforeach
        </select>
        <label for="identifier">{{ __('Identifier') }} (5.1.6)</label> @include('description._ric', ['term' => 'rico:Identifier'])
        <input id="identifier" name="identifier" maxlength="255" value="{{ old('identifier', $agent->identifier) }}">
        @include('description._fields', ['subject' => $agent, 'standard' => \App\Description\Elements::ISAAR_CPF, 'language' => $language])
        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Description control and publication') }}</strong></legend>
            <label for="description_identifier">{{ __('Description identifier') }} (5.4.1)</label> @include('description._ric', ['term' => null])
            <input id="description_identifier" name="description_identifier" maxlength="255" value="{{ old('description_identifier', $agent->description_identifier) }}">
            <label for="maintainer">{{ __('Maintained by') }}</label> @include('description._ric', ['term' => null])
            <select id="maintainer" name="maintainer" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($repositories as $repository)<option value="{{ $repository->id }}" @selected(old('maintainer', $agent->maintainer_id) === $repository->id)>{{ $repository->described('authorised-name', ['en']) }}</option>@endforeach
            </select>
            <label for="status">{{ __('Status') }} (5.4.4)</label> @include('description._ric', ['term' => null])
            <select id="status" name="status" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($statuses as $status)<option value="{{ $status->iri }}" @selected(old('status', $agent->descriptionStatus?->iri) === $status->iri)>{{ $status->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
            <label for="detail">{{ __('Level of detail') }} (5.4.5)</label> @include('description._ric', ['term' => null])
            <select id="detail" name="detail" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($details as $detail)<option value="{{ $detail->iri }}" @selected(old('detail', $agent->descriptionDetail?->iri) === $detail->iri)>{{ $detail->label(\App\Support\Vocab::languages()) }}</option>@endforeach
            </select>
            <label for="embargo_until">{{ __('Embargoed until (for a living person, under POPIA or GDPR: until then only those who may update it see it)') }}</label> @include('description._ric', ['term' => null])
            <input id="embargo_until" name="embargo_until" type="date" value="{{ old('embargo_until', $agent->embargo_until?->format('Y-m-d')) }}">
        </fieldset>
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
