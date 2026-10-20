{{-- OpenRiC Workspace - one repository. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $repository->described('authorised-name', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('repositories.index') }}">All repositories</a></p>
    <h1>{{ $repository->described('authorised-name', [app()->getLocale(), 'en']) }}</h1>
    <p>Repository @include('description._ric', ['term' => 'rico:CorporateBody'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>Persistent IRI:</strong> <code>{{ $repository->iri }}</code>@if($repository->identifier)<br><strong>Identifier:</strong> {{ $repository->identifier }}@endif</p>
    @php($canUpdate = auth()->user()->may('update', 'repository', $repository))
    @if($canUpdate)
        @include('description._actions', ['subject' => $repository, 'editRoute' => 'repositories.edit', 'required' => 'authorised-name'])
    @endif
    @include('description._view', ['subject' => $repository, 'standard' => \App\Description\Elements::ISDIAH, 'drafts' => $canUpdate])

    @include('description._names', ['subject' => $repository, 'kind' => 'repositories', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $repository, 'kind' => 'repositories', 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $repository, 'kind' => 'repositories', 'relations' => $relations, 'canUpdate' => $canUpdate])
    @include('description._relations', ['subject' => $repository, 'kind' => 'repositories', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Place access points', 'types' => ['place'], 'section' => 'pl', 'add' => 'place|target', 'addLabel' => 'Add a place'])
    @include('description._relations', ['subject' => $repository, 'kind' => 'repositories', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Units of description about it', 'types' => ['subject'], 'section' => 'su', 'add' => 'subject|target', 'addLabel' => 'Add a unit of description about it'])
    @include('description._dates', ['subject' => $repository, 'kind' => 'repositories', 'heading' => 'Dates', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])

    <h2>Contact points</h2>
    @foreach($repository->contactPoints as $contact)
        @unless($canUpdate)
            <p>{{ $contact->label ?: 'Contact' }}@if($contact->is_primary) (primary)@endif: {{ collect([$contact->contact_person, $contact->street_address, $contact->city, $contact->region, $contact->postal_code, $contact->country_code, $contact->telephone, $contact->fax, $contact->email, $contact->website])->filter()->join(', ') }}</p>
            @continue
        @endunless
        <details>
            <summary>{{ $contact->label ?: 'Contact' }}@if($contact->is_primary) (primary)@endif: {{ collect([$contact->street_address, $contact->city, $contact->country_code, $contact->telephone, $contact->email])->filter()->join(', ') }} <small lang="en">- edit or remove</small></summary>
            <form method="post" action="{{ route('repositories.contacts.update', [$repository, $contact]) }}">
                @csrf
                @include('repositories._contact-fields', ['contact' => $contact, 'prefix' => 'c'.$loop->index])
                <button type="submit">Save contact point</button>
            </form>
            <form method="post" action="{{ route('repositories.contacts.destroy', [$repository, $contact]) }}">
                @csrf
                <button type="submit">Remove contact point</button>
            </form>
        </details>
    @endforeach
    @if($canUpdate)
    <details>
        <summary>Add a contact point</summary>
        <form method="post" action="{{ route('repositories.contacts.store', $repository) }}">
            @csrf
            @include('repositories._contact-fields', ['contact' => null, 'prefix' => 'new'])
            <button type="submit">Add contact point</button>
        </form>
    </details>
    @endif

    <h2>Control</h2>
    <p>Description identifier: {{ $repository->description_identifier ?? 'none' }}.
       Status: {{ $repository->descriptionStatus?->label(['en', 'und']) ?? 'not set' }}.
       Level of detail: {{ $repository->descriptionDetail?->label(['en', 'und']) ?? 'not set' }}.</p>
@endsection
