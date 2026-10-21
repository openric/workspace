{{-- OpenRiC Workspace - one authority record; with $print, the record alone, without its forms. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = $agent->described('agent-authorised-name', [app()->getLocale(), 'en']))
@section('title', $name)
@section('content')
    @unless($print)<p><a href="{{ route('agents.index') }}">Authority records</a></p>@endunless
    <h1>{{ $name }}</h1>
    @include('clipboard._button', ['type' => 'agents', 'id' => $agent->id])
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>Type of entity:</strong> {{ $agent->agentType->label(['en', 'und']) }} @include('description._ric', ['term' => \App\Relations\EntityKinds::ricClass($agent)])
        @if($agent->identifier)<br><strong>Identifier:</strong> {{ $agent->identifier }}@endif
        <br><strong>Persistent IRI:</strong> <code>{{ $agent->iri }}</code>
        @if($agent->isEmbargoed())<br><strong>Embargoed until {{ $agent->embargo_until->format('Y-m-d') }}:</strong> only those who may update authority records see it.@endif
        @unless($print)<br><strong>Completeness:</strong> {{ $completeness['percent'] }}% of the ISAAR(CPF) elements.
            @if($completeness['missing'])Missing essential elements: {{ implode(', ', $completeness['missing']) }}.@endif @endunless</p>
    @php($canUpdate = ! $print && auth()->user()->may('update', 'authority', $agent))
    @unless($print)
        @if($canUpdate)
            @include('description._actions', ['subject' => $agent, 'editRoute' => 'agents.edit', 'required' => 'agent-authorised-name'])
        @endif
        <p><a href="{{ route('agents.print', $agent) }}">Print view</a>
            @if(\App\Description\EacCpf::exportable($agent)) · <a href="{{ route('agents.eac', $agent) }}">Export as EAC-CPF</a>@endif
            @if($canUpdate && auth()->user()->may('create', 'authority')) · <a href="{{ route('agents.split-form', $agent) }}">Split into two</a>@endif
            @if(auth()->user()->may('delete', 'authority', $agent)) · <a href="{{ route('agents.merge-form', $agent) }}">Merge into another</a>@endif</p>
    @endunless
    @include('description._view', ['subject' => $agent, 'standard' => \App\Description\Elements::ISAAR_CPF, 'drafts' => auth()->user()->may('update', 'authority', $agent)])

    @include('description._names', ['subject' => $agent, 'kind' => 'agents', 'canUpdate' => $canUpdate])
    @include('description._identifiers', ['subject' => $agent, 'kind' => 'agents', 'canUpdate' => $canUpdate])
    @include('description._dates', ['subject' => $agent, 'kind' => 'agents', 'heading' => 'Dates (5.2.1)', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])
    @if($agent->typeNotation() === 'person')
        @include('description._relations', ['subject' => $agent, 'kind' => 'agents', 'relations' => $relations, 'canUpdate' => $canUpdate,
            'heading' => 'Occupations (5.2.5)', 'types' => ['occupation'], 'section' => 'oc', 'add' => 'occupation|source', 'addLabel' => 'Add an occupation'])
    @endif
    @include('description._relations', ['subject' => $agent, 'kind' => 'agents', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Legal status (5.2.4)', 'types' => ['legal-status'], 'section' => 'ls', 'add' => 'legal-status|source', 'addLabel' => 'Add a legal status'])
    @include('description._relations', ['subject' => $agent, 'kind' => 'agents', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Places (5.2.3)', 'types' => ['place'], 'section' => 'pl', 'add' => 'place|target', 'addLabel' => 'Add a place'])
    @include('description._relations', ['subject' => $agent, 'kind' => 'agents', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Relationships (5.3) and units of description'])
    @include('description._relations', ['subject' => $agent, 'kind' => 'agents', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Units of description about it', 'types' => ['subject'], 'section' => 'su', 'add' => 'subject|target', 'addLabel' => 'Add a unit of description about it'])

    <h2>Contact points</h2>
    @forelse($agent->contactPoints as $contact)
        @unless($canUpdate)
            <p>{{ $contact->label ?: 'Contact' }}@if($contact->is_primary) (primary)@endif: {{ collect([$contact->contact_person, $contact->street_address, $contact->city, $contact->region, $contact->postal_code, $contact->country_code, $contact->telephone, $contact->fax, $contact->email, $contact->website])->filter()->join(', ') }}</p>
            @continue
        @endunless
        <details>
            <summary>{{ $contact->label ?: 'Contact' }}@if($contact->is_primary) (primary)@endif: {{ collect([$contact->street_address, $contact->city, $contact->country_code, $contact->telephone, $contact->email])->filter()->join(', ') }} <small lang="en">- edit or remove</small></summary>
            <form method="post" action="{{ route('agents.contacts.update', [$agent, $contact]) }}">
                @csrf
                @include('repositories._contact-fields', ['contact' => $contact, 'prefix' => 'c'.$loop->index])
                <button type="submit">Save contact point</button>
            </form>
            <form method="post" action="{{ route('agents.contacts.destroy', [$agent, $contact]) }}">
                @csrf
                <button type="submit">Remove contact point</button>
            </form>
        </details>
    @empty
        <p>None.</p>
    @endforelse
    @if($canUpdate)
        <details>
            <summary>Add a contact point</summary>
            <form method="post" action="{{ route('agents.contacts.store', $agent) }}">
                @csrf
                @include('repositories._contact-fields', ['contact' => null, 'prefix' => 'new'])
                <button type="submit">Add contact point</button>
            </form>
        </details>
    @endif

    <h2>Control (5.4)</h2>
    <p>Description identifier: {{ $agent->description_identifier ?? 'none' }}.
        Maintained by: {{ $agent->maintainer ? $agent->maintainer->described('authorised-name', ['en']) : 'not set' }}.
        Status: {{ $agent->descriptionStatus?->label(['en', 'und']) ?? 'not set' }}.
        Level of detail: {{ $agent->descriptionDetail?->label(['en', 'und']) ?? 'not set' }}.</p>
@endsection
