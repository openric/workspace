{{-- OpenRiC Workspace - the vendors, found by name, code or registration and filtered (Heratio's ahg-vendor list). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Vendors'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Vendors') }}</h1>
    @include('vendors._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <form method="get" action="{{ route('vendors.list') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Name, code or registration') }}</label><input id="q" name="q" value="{{ $q }}" maxlength="200"></div>
        @foreach(['status' => [$statuses, __('Status')], 'type' => [$types, __('Type')], 'service' => [$services, __('Service')]] as $field => [$options, $label])
            <div><label for="{{ $field }}" style="margin-top: 0">{{ $label }}</label><select id="{{ $field }}" name="{{ $field }}" style="font: inherit; padding: .5rem"><option value="">{{ __('Any') }}</option>
                @foreach($options as $o)<option value="{{ $o->notation }}" @selected(request($field) === $o->notation)>{{ $o->label($lang) }}</option>@endforeach</select></div>
        @endforeach
        <label><input type="checkbox" name="insured" value="1" @checked(request()->boolean('insured'))> {{ __('Insured now') }}</label>
        <label><input type="checkbox" name="preferred" value="1" @checked(request()->boolean('preferred'))> {{ __('Preferred') }}</label>
        <button type="submit" style="margin-top: 0">{{ __('Search') }}</button>
    </form>
    <table><thead><tr><th scope="col">{{ __('Vendor') }}</th><th scope="col">{{ __('Code') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Services') }}</th></tr></thead>
        <tbody>@forelse($vendors as $v)<tr><td><a href="{{ route('vendors.show', $v) }}">{{ \App\Relations\EntityKinds::label($v->agent) }}</a>@if($v->is_preferred) · {{ __('preferred') }}@endif</td><td>{{ $v->vendor_code }}</td>
            <td>{{ $v->vendorType->label($lang) }}</td><td>{{ $v->status->label($lang) }}</td><td>{{ $v->services->map(fn ($s) => $s->serviceType->label($lang))->join(', ') }}</td></tr>
        @empty<tr><td colspan="5">{{ __('No vendor matches.') }}</td></tr>@endforelse</tbody></table>
@endsection
