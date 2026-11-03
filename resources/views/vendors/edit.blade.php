{{-- OpenRiC Workspace - changing a vendor profile (Heratio's ahg-vendor edit). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Edit :name', ['name' => \App\Relations\EntityKinds::label($vendor->agent)]))
@section('content')
    <h1>{{ __('Edit :name', ['name' => \App\Relations\EntityKinds::label($vendor->agent)]) }}</h1>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('vendors.update', $vendor) }}">@csrf @include('vendors._form') <button type="submit">{{ __('Save') }}</button></form>
    @if(auth()->user()->may('delete', 'vendor'))
        <form method="post" action="{{ route('vendors.destroy', $vendor) }}">@csrf<button type="submit">{{ __('Remove the vendor profile') }}</button> <small>{{ __('The authority record stays. A vendor that has done work is made inactive instead.') }}</small></form>
    @endif
@endsection
