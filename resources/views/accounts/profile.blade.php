{{-- OpenRiC Workspace - the signed-in account's own profile (Heratio's user profile). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $account, $contact (?ContactPoint) and $languages (all it may translate into, from roles and its own). --}}
@extends('layouts.app')
@section('title', 'Your profile')
@section('content')
    <h1>{{ $account->name }}</h1>
    <p>{{ $account->email }}</p>
    <p><a href="{{ route('account.edit') }}">Edit your profile</a> · <a href="{{ route('password.edit') }}">Change your password</a> · <a href="{{ route('account.access') }}">What you may do</a></p>

    <h2>Your roles</h2>
    @forelse($account->roles as $role)<p style="margin: .2rem 0">{{ $role->name() }}</p>@empty<p>None of your own.</p>@endforelse
    <h2>Languages you may translate into</h2>
    <p>{{ $languages ? implode(', ', $languages) : 'None.' }}</p>

    <h2>Contact details</h2>
    @if($contact && collect(\App\Models\ContactPoint::FIELDS)->contains(fn ($f) => filled($contact->$f)))
        <dl>
            @foreach(['contact_person' => 'Contact person', 'street_address' => 'Street address', 'city' => 'City', 'region' => 'Region', 'postal_code' => 'Postal code', 'country_code' => 'Country', 'telephone' => 'Telephone', 'fax' => 'Fax', 'email' => 'Email', 'website' => 'Website'] as $field => $label)
                @if(filled($contact->$field))<dt>{{ $label }}</dt><dd>{{ $contact->$field }}</dd>@endif
            @endforeach
        </dl>
    @else
        <p>None given yet.</p>
    @endif
@endsection
