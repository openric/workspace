{{-- OpenRiC Workspace - contact point form fields. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($labels = ['label' => 'Label, e.g. Reading room', 'contact_person' => 'Contact person', 'street_address' => 'Street address', 'city' => 'City', 'region' => 'Region', 'postal_code' => 'Postal code', 'country_code' => 'Country (ISO 3166-1 code, e.g. ZA)', 'telephone' => 'Telephone', 'fax' => 'Fax', 'email' => 'Email', 'website' => 'Website'])
@foreach(\App\Models\ContactPoint::FIELDS as $field)
    <label for="{{ $prefix }}-{{ $field }}">{{ $labels[$field] }}</label> @include('description._ric', ['term' => null])
    @if($field === 'street_address')
        <textarea id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" rows="2" style="width: 100%; font: inherit">{{ $contact?->$field }}</textarea>
    @else
        <input id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" value="{{ $contact?->$field }}" @if($field === 'email') type="email" @elseif($field === 'website') type="url" @endif>
    @endif
@endforeach
<label style="font-weight: normal"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1" style="width: auto" @checked($contact?->is_primary)> Primary contact</label>
