{{-- OpenRiC Workspace - work sent to vendors, found and filtered (Heratio's ahg-vendor transactions). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Transactions'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Transactions') }} @include('description._ric', ['term' => 'rico:Activity'])</h1>
    @include('vendors._nav')
    <form method="get" action="{{ route('vendors.transactions') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Number or vendor') }}</label><input id="q" name="q" value="{{ $q }}" maxlength="200"></div>
        @foreach(['status' => [$statuses, __('Status')], 'service' => [$services, __('Service')]] as $field => [$options, $label])
            <div><label for="{{ $field }}" style="margin-top: 0">{{ $label }}</label><select id="{{ $field }}" name="{{ $field }}" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($options as $o)<option value="{{ $o->notation }}" @selected(request($field) === $o->notation)>{{ $o->label($lang) }}</option>@endforeach</select></div>
        @endforeach
        <label><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))> {{ __('Overdue only') }}</label>
        <button type="submit" style="margin-top: 0">{{ __('Search') }}</button>
    </form>
    @include('vendors._transactions', ['none' => __('No transaction matches.')])
@endsection
