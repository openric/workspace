{{-- OpenRiC Workspace - one repository. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $repository->described('authorised-name', [app()->getLocale(), 'en']))
@section('content')
    <p><a href="{{ route('repositories.index') }}">All repositories</a></p>
    <h1>{{ $repository->described('authorised-name', [app()->getLocale(), 'en']) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p><strong>Persistent IRI:</strong> <code>{{ $repository->iri }}</code>@if($repository->identifier)<br><strong>Identifier:</strong> {{ $repository->identifier }}@endif</p>
    @php($languages = $repository->descriptionValues->pluck('language')->unique()->sort()->values())
    <p>Edit in:
        @foreach($languages as $lang)<a href="{{ route('repositories.edit', [$repository, 'language' => $lang]) }}">{{ $lang }}</a> @endforeach
        <a href="{{ route('repositories.edit', [$repository, 'language' => 'en']) }}">another language</a></p>
    @foreach($elements as $element)
        @php($values = $repository->descriptionValues->where('element_id', $element->id))
        @continue($values->isEmpty())
        <h2>{{ $element->label(['en', 'und']) }}</h2>
        @foreach($values->sortBy('language') as $value)
            <p lang="{{ $value->language }}">{{ $value->value }}</p>
            <p><small>{{ $value->language }}, {{ $value->is_original ? 'original' : 'translation' }}, {{ $value->state->label(['en', 'und']) }}</small></p>
        @endforeach
    @endforeach

    <h2>Contact points</h2>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @foreach($repository->contactPoints as $contact)
        <details>
            <summary>{{ $contact->label ?: 'Contact' }}@if($contact->is_primary) (primary)@endif: {{ collect([$contact->street_address, $contact->city, $contact->country_code, $contact->telephone, $contact->email])->filter()->join(', ') }}</summary>
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
    <details>
        <summary>Add a contact point</summary>
        <form method="post" action="{{ route('repositories.contacts.store', $repository) }}">
            @csrf
            @include('repositories._contact-fields', ['contact' => null, 'prefix' => 'new'])
            <button type="submit">Add contact point</button>
        </form>
    </details>

    <h2>Control</h2>
    <p>Description identifier: {{ $repository->description_identifier ?? 'none' }}.
       Status: {{ $repository->descriptionStatus?->label(['en', 'und']) ?? 'not set' }}.
       Level of detail: {{ $repository->descriptionDetail?->label(['en', 'und']) ?? 'not set' }}.</p>
@endsection
