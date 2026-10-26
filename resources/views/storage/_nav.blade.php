{{-- OpenRiC Workspace - the storage pages' own links. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p><a href="{{ route('containers.index') }}">{{ __('Containers') }}</a> · <a href="{{ route('storage.places') }}">{{ __('Physical storage') }}</a> · <a href="{{ route('containers.holdings') }}">{{ __('Holdings as CSV') }}</a>@if(auth()->user()->may('create', 'storage')) · <a href="{{ route('containers.create') }}">{{ __('New container') }}</a>@endif</p>
<form method="get" action="{{ route('containers.scan') }}" style="display: flex; gap: .5rem; align-items: end; flex-wrap: wrap">
    <div><label for="barcode" style="margin-top: 0">{{ __('Scan or type a barcode') }}</label><input id="barcode" name="barcode" maxlength="255" autocomplete="off" inputmode="text"></div>
    <button type="submit" style="margin-top: 0">{{ __('Find') }}</button>
</form>
