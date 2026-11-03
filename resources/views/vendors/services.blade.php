{{-- OpenRiC Workspace - the services vendors offer and what each needs at the institution (Heratio's ahg-vendor service types). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Service types'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Service types') }} @include('description._ric', ['term' => 'rico:ActivityType'])</h1>
    @include('vendors._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('What each service needs before records are sent: a service that needs insurance goes only to a vendor whose cover is current, or with insurance arranged for the work; one that needs a valuation only once every record has its declared value. The usual number of days sets the date the records are due back.') }}</p>
    <form method="post" action="{{ route('vendors.services.save') }}">
        @csrf
        <table><thead><tr><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Needs insurance') }}</th><th scope="col">{{ __('Needs a valuation') }}</th><th scope="col">{{ __('Usual days') }}</th><th scope="col">{{ __('Offered') }}</th></tr></thead>
            <tbody>@foreach($services as $s)@php($n = $s->concept->notation)<tr><td>{{ $s->concept->label($lang) }}</td>
                <td><input type="checkbox" name="services[{{ $n }}][requires_insurance]" value="1" @checked($s->requires_insurance) @disabled(! $canUpdate) aria-label="{{ __('Needs insurance') }}"></td>
                <td><input type="checkbox" name="services[{{ $n }}][requires_valuation]" value="1" @checked($s->requires_valuation) @disabled(! $canUpdate) aria-label="{{ __('Needs a valuation') }}"></td>
                <td><input name="services[{{ $n }}][typical_duration_days]" value="{{ $s->typical_duration_days }}" inputmode="numeric" size="4" @disabled(! $canUpdate) aria-label="{{ __('Usual days') }}"></td>
                <td><input type="checkbox" name="services[{{ $n }}][is_active]" value="1" @checked($s->is_active) @disabled(! $canUpdate) aria-label="{{ __('Offered') }}"></td></tr>@endforeach</tbody></table>
        @if($canUpdate)<button type="submit">{{ __('Save') }}</button>@endif
    </form>
@endsection
