{{-- OpenRiC Workspace - describe a rule or mandate (roadmap phase 23b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Describe rule'))
@section('content')
    <p><a href="{{ route('rules.show', $rule) }}">{{ __('Back to the rule') }}</a></p>
    <h1>{{ __('Describe :name', ['name' => $rule->described('rule-name', [$language, 'en'])]) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft.') }}</p>
    <form method="post" action="{{ route('rules.update', $rule) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        @include('rules._type-field', ['types' => $types, 'current' => $rule->rule_type_id])
        @include('rules._status-field', ['current' => old('status', $rule->status?->notation)])
        <label for="authority_iri">{{ __('The rule elsewhere') }}</label> @include('description._ric', ['term' => 'owl:sameAs'])
        <input id="authority_iri" name="authority_iri" type="url" maxlength="2048" value="{{ old('authority_iri', $rule->authority_iri) }}" aria-describedby="authority-help">
        <small id="authority-help">{{ __('The address of the same rule in a legal database or a government gazette, if it has one.') }}</small>
        @include('description._fields', ['subject' => $rule, 'standard' => \App\Description\Elements::RULE, 'language' => $language])
        <h2>{{ __('Control') }}</h2>
        <label for="description_identifier">{{ __('Description identifier') }}</label> @include('description._ric', ['term' => null])
        <input id="description_identifier" name="description_identifier" value="{{ old('description_identifier', $rule->description_identifier) }}">
        <label for="description_status">{{ __('Status') }}</label> @include('description._ric', ['term' => null])
        <select id="description_status" name="description_status" style="font: inherit; padding: .5rem">
            <option value="">{{ __('Not set') }}</option>
            @foreach($statuses as $status)<option value="{{ $status->iri }}" @selected(old('description_status', $rule->descriptionStatus?->iri) === $status->iri)>{{ $status->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        <label for="description_detail">{{ __('Level of detail') }}</label> @include('description._ric', ['term' => null])
        <select id="description_detail" name="description_detail" style="font: inherit; padding: .5rem">
            <option value="">{{ __('Not set') }}</option>
            @foreach($details as $detail)<option value="{{ $detail->iri }}" @selected(old('description_detail', $rule->descriptionDetail?->iri) === $detail->iri)>{{ $detail->label(\App\Support\Vocab::languages()) }}</option>@endforeach
        </select>
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
