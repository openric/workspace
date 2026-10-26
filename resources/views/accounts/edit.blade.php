{{-- OpenRiC Workspace - edit an account: an administrator's, or the owner's own profile (as Heratio's user edit and profileEdit). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $account, $own (the owner editing their own), $contact (?ContactPoint) and $languages (the account's own translation languages). --}}
@extends('layouts.app')
@section('title', $own ? 'Edit your profile' : 'Edit '.$account->name)
@section('content')
    <p>@if($own)<a href="{{ route('account.profile') }}">{{ __('Profile') }}</a>@else<a href="{{ route('accounts.show', $account) }}">{{ $account->name }}</a>@endif</p>
    <h1>{{ $own ? __('Edit profile') : __('Edit :name', ['name' => $account->name]) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <h2>{{ __('User') }}</h2>
    <form method="post" action="{{ $own ? route('account.update') : route('accounts.update', $account) }}">
        @csrf
        <label for="name">{{ __('Name') }}</label>
        <input id="name" name="name" required maxlength="1024" value="{{ old('name', $account->name) }}">
        <label for="email">{{ __('Email, used to sign in') }}</label>
        <input id="email" name="email" type="email" required maxlength="255" value="{{ old('email', $account->email) }}" aria-describedby="email-help">
        <small id="email-help">{{ __('A password link goes to this address.') }}</small>
        @unless($own)
            <label for="translation_languages">{{ __('Languages this account may translate into, besides those of its roles') }}</label>
            <input id="translation_languages" name="translation_languages" value="{{ old('translation_languages', implode(', ', $languages)) }}" aria-describedby="languages-help" placeholder="{{ __('af, fr') }}">
            <small id="languages-help">{{ __('BCP 47 tags, separated by commas, as Heratio\'s per-user translate languages. Its roles may add more.') }}</small>
        @endunless
        <button type="submit">{{ __('Save user') }}</button>
    </form>

    <h2>{{ __('Contact details') }}</h2>
    <form method="post" action="{{ $own ? route('account.contact') : route('accounts.contact', $account) }}">
        @csrf
        @include('repositories._contact-fields', ['contact' => $contact, 'prefix' => 'contact'])
        <button type="submit">{{ __('Save contact details') }}</button>
    </form>

    @if($own)
        <p><a href="{{ route('password.edit') }}">{{ __('Change password') }}</a></p>
    @endif
@endsection
