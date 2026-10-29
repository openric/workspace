{{-- OpenRiC Workspace - edit a donor agreement (Heratio's agreement edit: details, parties, dates, transfer, financial terms, signatures and terms). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Edit agreement'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($value = fn (string $column) => old($column, $agreement->{$column} instanceof \DateTimeInterface ? $agreement->{$column}->format('Y-m-d') : $agreement->{$column}))
    <p><a href="{{ route('agreements.show', $agreement) }}">{{ __('Back to the agreement') }}</a></p>
    <h1>{{ __('Edit agreement') }}: {{ $agreement->heading([$language, 'en']) }}</h1>
    <p>{{ __('Values in') }} <strong>{{ $language }}</strong>{{ __('. Fill in what you know; empty fields are left out. A value you change goes back to draft.') }}</p>
    <form method="post" action="{{ route('agreements.update', $agreement) }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', $language) }}">
        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Details') }}</strong></legend>
            <label for="number">{{ __('Agreement number') }}</label> @include('description._ric', ['term' => 'rico:Identifier'])
            <input id="number" name="number" required maxlength="255" value="{{ old('number', $agreement->number()) }}">
            @foreach(['type' => ['Agreement type', $types, 'rico:hasDocumentaryFormType'], 'status' => ['Status', $statuses, null]] as $field => [$what, $list, $term])
                <label for="{{ $field }}">{{ __($what) }}</label> @include('description._ric', ['term' => $term])
                <select id="{{ $field }}" name="{{ $field }}" style="font: inherit; padding: .5rem">
                    @foreach($list as $c)<option value="{{ $c->notation }}" @selected(old($field, $agreement->{$field}?->notation) === $c->notation)>{{ $c->label($lang) }}</option>@endforeach
                </select>
            @endforeach
            <label for="repository">{{ __('Repository') }}</label> @include('description._ric', ['term' => null])
            <select id="repository" name="repository" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($repositories as $r)<option value="{{ $r->id }}" @selected(old('repository', $agreement->repository_id) === $r->id)>{{ $r->described('authorised-name', [app()->getLocale(), 'en']) }}</option>@endforeach
            </select>
            @foreach(['parent' => ['Part of', 'parent_id', 'The agreement this one belongs to, such as the deed an addendum amends.'], 'supersedes' => ['Supersedes', 'supersedes_id', 'The agreement this one replaces.']] as $field => [$what, $column, $help])
                <label for="{{ $field }}">{{ __($what) }}</label> @include('description._ric', ['term' => null])
                <small id="{{ $field }}-help">{{ __($help) }}</small>
                <select id="{{ $field }}" name="{{ $field }}" aria-describedby="{{ $field }}-help" style="font: inherit; padding: .5rem">
                    <option value="">{{ __('None') }}</option>
                    @foreach($others as $o)<option value="{{ $o->id }}" @selected(old($field, $agreement->{$column}) === $o->id)>{{ $o->heading([app()->getLocale(), 'en']) }}</option>@endforeach
                </select>
            @endforeach
            <input type="hidden" name="is_template" value="0">
            <label style="font-weight: normal"><input type="checkbox" name="is_template" value="1" @checked(old('is_template', $agreement->is_template)) style="width: auto"> {{ __('A template for new agreements') }}</label>
        </fieldset>
        @foreach([
            'Dates' => ['agreed_on' => 'Agreement date', 'effective_on' => 'Effective date', 'expires_on' => 'Expiry date', 'review_on' => 'Review date', 'terminated_on' => 'Termination date'],
            'Parties' => ['legal_representative' => 'Legal representative', 'legal_representative_title' => 'Legal representative title', 'repository_representative' => 'Repository representative', 'repository_representative_title' => 'Repository representative title'],
            'Transfer' => ['transfer_method' => 'Transfer method', 'transferred_on' => 'Transfer date', 'received_by' => 'Received by'],
            'Signatures' => ['donor_signature_name' => 'Donor signature name', 'donor_signed_on' => 'Donor signature date', 'repository_signature_name' => 'Repository signature name', 'repository_signed_on' => 'Repository signature date', 'witness_name' => 'Witness name', 'witnessed_on' => 'Witness date'],
        ] as $legend => $fields)
            <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
                <legend><strong>{{ __($legend) }}</strong></legend>
                @foreach($fields as $column => $what)
                    @php($isDay = in_array($column, \App\Models\Agreement::DATES, true))
                    <label for="{{ $column }}">{{ __($what) }}</label> @include('description._ric', ['term' => null])
                    <input id="{{ $column }}" name="{{ $column }}" value="{{ $value($column) }}" @if($isDay) placeholder="YYYY-MM-DD" maxlength="10" @else maxlength="500" @endif>
                @endforeach
            </fieldset>
        @endforeach
        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Financial terms') }}</strong></legend>
            <input type="hidden" name="has_financial_terms" value="0">
            <label style="font-weight: normal"><input type="checkbox" name="has_financial_terms" value="1" @checked(old('has_financial_terms', $agreement->has_financial_terms)) style="width: auto"> {{ __('Has financial terms') }}</label>
            <label for="purchase_amount">{{ __('Purchase amount') }}</label> @include('description._ric', ['term' => null])
            <input id="purchase_amount" name="purchase_amount" inputmode="decimal" maxlength="20" value="{{ $value('purchase_amount') }}">
            <label for="currency">{{ __('Currency (ISO 4217)') }}</label> @include('description._ric', ['term' => null])
            <input id="currency" name="currency" maxlength="3" value="{{ $value('currency') }}">
        </fieldset>
        @include('description._fields', ['subject' => $agreement, 'standard' => \App\Description\Elements::AGREEMENT, 'language' => $language])
        <button type="submit">{{ __('Save') }}</button>
    </form>
@endsection
