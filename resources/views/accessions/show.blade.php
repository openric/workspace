{{-- OpenRiC Workspace - one accession: basic info, its intake, texts, dates, donors, creators, storage and containers, the descriptions made from it, agreements, appraisal, valuation and rights, the CAAIS statements, deaccessions and its record's history (Heratio's accession show). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $accession->heading([app()->getLocale(), 'en']))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('accessions.index') }}">{{ __('Accessions') }}</a></p>
    <h1>{{ $accession->heading([app()->getLocale(), 'en']) }}</h1>
    <p>{{ __('Accession') }} @include('description._ric', ['term' => 'rico:Activity'])</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><strong>{{ __('Persistent IRI') }}:</strong> <code>{{ $accession->iri }}</code>
        <br><strong>{{ __('Date created') }}:</strong> {{ $accession->created_at->format('Y-m-d') }}. <strong>{{ __('Date modified') }}:</strong> {{ $accession->updated_at->format('Y-m-d') }}.
        @if($source = $accession->descriptionValues->firstWhere('is_original', true)?->language)<strong>{{ __('Source language') }}:</strong> {{ $source }}.@endif</p>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; margin: 1rem 0">
        @if($canUpdate)<a href="{{ route('accessions.edit', [$accession, 'language' => $accession->descriptionValues->first()?->language ?? 'en']) }}" style="display: inline-block; padding: .5rem 1rem; background: var(--accent); color: var(--bg); text-decoration: none">{{ __('Edit accession record') }}</a>
            <a href="{{ route('history', ['accessions', $accession->id]) }}" style="display: inline-block; padding: .5rem 1rem; border: 1px solid var(--line); text-decoration: none">{{ __('History') }}</a>@endif
        <a href="{{ route('accessions.caais', $accession) }}" style="display: inline-block; padding: .5rem 1rem; border: 1px solid var(--line); text-decoration: none">{{ __('Export CAAIS') }}</a>
        @if(auth()->user()->may('delete', 'accession', $accession))<a href="{{ route('deletion.confirm', ['accessions', $accession->id]) }}" style="display: inline-block; padding: .5rem 1rem; border: 1px solid var(--error); color: var(--error); text-decoration: none">{{ __('Delete') }}</a>@endif
    </div>

    @include('accessions._intake')

    <h2>{{ __('Basic info') }}</h2>
    <p><strong>{{ __('Accession number') }}:</strong> {{ $accession->number() }}
        <br><strong>{{ __('Repository') }}:</strong> @if($accession->repository)<a href="{{ route('repositories.show', $accession->repository) }}">{{ $accession->repository->described('authorised-name', [app()->getLocale(), 'en']) }}</a>@else{{ __('not set') }}@endif
        <br><strong>{{ __('Acquisition type') }}:</strong> {{ $accession->acquisitionType?->label($lang) ?? __('not set') }}
        <br><strong>{{ __('Resource type') }}:</strong> {{ $accession->resourceType?->label($lang) ?? __('not set') }}
        <br><strong>{{ __('Processing status') }}:</strong> {{ $accession->processingStatus?->label($lang) ?? __('not set') }}
        <br><strong>{{ __('Processing priority') }}:</strong> {{ $accession->processingPriority?->label($lang) ?? __('not set') }}</p>
    @include('description._view', ['subject' => $accession, 'standard' => \App\Description\Elements::ACCESSION, 'drafts' => true])
    @include('description._dates', ['subject' => $accession, 'kind' => 'accessions', 'heading' => 'Dates (CAAIS 3.1)', 'canUpdate' => $canUpdate, 'choices' => $dateChoices])

    <section id="donors">
        @include('description._relations', ['subject' => $accession, 'kind' => 'accessions', 'relations' => $relations, 'canUpdate' => $canUpdate,
            'heading' => 'Donor/Transferring body area', 'types' => ['participation'], 'section' => 'dn', 'add' => 'participation|source', 'addLabel' => 'Add a donor, in the donor role'])
        @foreach($donors as $donor)
            @php($confidential = $accession->sourceConfidentiality->firstWhere('agent_id', $donor->id))
            <h3 style="margin-bottom: .2rem">{{ $donor->described('agent-authorised-name', [app()->getLocale(), 'en']) }}</h3>
            @php($contact = $donor->contactPoints()->orderByDesc('is_primary')->first())
            <p style="margin: .2rem 0">{{ __('Primary contact information') }}: @if($contact){{ collect([$contact->contact_person, $contact->street_address, $contact->city, $contact->region, $contact->postal_code, $contact->country_code, $contact->telephone, $contact->email])->filter()->join(', ') }}@else{{ __('none') }}@endif
                (<a href="{{ route('agents.show', $donor) }}">{{ __('Related donor record') }}</a>)</p>
            <p style="margin: .2rem 0">{{ __('Source confidentiality') }} (CAAIS 2.1.6): {{ $confidential?->confidentiality->label($lang) ?? __('None - may be shared') }}</p>
            @if($canUpdate)
                <form method="post" action="{{ route('accessions.confidentiality', $accession) }}" style="display: flex; gap: .5rem; align-items: end; flex-wrap: wrap">
                    @csrf
                    <input type="hidden" name="donor" value="{{ $donor->id }}">
                    <div><label for="conf-{{ $loop->index }}" style="margin-top: 0">{{ __('Confidentiality') }}</label>
                        <select id="conf-{{ $loop->index }}" name="confidentiality" style="font: inherit; padding: .5rem">
                            <option value="">{{ __('None - may be shared') }}</option>
                            @foreach($choices['confidentiality'] as $c)<option value="{{ $c->notation }}" @selected($confidential?->confidentiality->notation === $c->notation)>{{ $c->label($lang) }}</option>@endforeach
                        </select></div>
                    <button type="submit" style="margin-top: 0">{{ __('Save') }}</button>
                </form>
            @endif
        @endforeach
    </section>
    @include('description._relations', ['subject' => $accession, 'kind' => 'accessions', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Creators', 'types' => ['material-creator'], 'section' => 'cr', 'add' => 'material-creator|source', 'addLabel' => 'Add a creator of the material'])
    @include('description._relations', ['subject' => $accession, 'kind' => 'accessions', 'relations' => $relations, 'canUpdate' => $canUpdate,
        'heading' => 'Accruals', 'types' => ['accrual'], 'section' => 'ac', 'add' => 'accrual|source', 'addLabel' => 'Add the accession this one is an accrual to'])
    @if(auth()->user()->mayAnywhere('read', 'storage'))
        @include('description._relations', ['subject' => $accession, 'kind' => 'accessions', 'relations' => $relations->filter(fn ($r) => $r->kind() !== 'storage' || auth()->user()->may('read', 'storage', $r->other)), 'canUpdate' => $canUpdate,
            'heading' => 'Physical storage', 'types' => ['storage'], 'section' => 'st', 'add' => 'storage|source', 'addLabel' => 'Link physical storage'])
        @include('accessions._containers')
    @endif
    <section id="descriptions">
        @include('description._relations', ['subject' => $accession, 'kind' => 'accessions', 'relations' => $relations, 'canUpdate' => $canUpdate,
            'heading' => 'Archival descriptions', 'types' => ['result'], 'section' => 're', 'add' => 'result|source', 'addLabel' => 'Link an archival description made from it'])
        @if($canUpdate && $mayDescribe)
            <details>
                <summary>{{ __('Create an archival description from this accession') }}</summary>
                <form method="post" action="{{ route('accessions.description', $accession) }}">
                    @csrf
                    <small>{{ __('It takes the accession title (or number), the number as its reference code and the scope and content.') }}</small>
                    <label for="d-level">{{ __('Level of description') }}</label>
                    <select id="d-level" name="level" style="font: inherit; padding: .5rem">@foreach($levels as $level)<option value="{{ $level->iri }}" @selected($level->notation === 'fonds')>{{ $level->label($lang) }}</option>@endforeach</select>
                    <label for="d-repository">{{ __('Repository') }}</label>
                    <select id="d-repository" name="repository" style="font: inherit; padding: .5rem">
                        @foreach($repositories as $r)<option value="{{ $r->id }}" @selected($accession->repository_id === $r->id)>{{ $r->described('authorised-name', [app()->getLocale(), 'en']) }}</option>@endforeach
                    </select>
                    <label for="d-language">{{ __('Language (BCP 47)') }}</label>
                    <input id="d-language" name="language" required value="{{ app()->getLocale() }}">
                    <button type="submit">{{ __('Create archival description') }}</button>
                </form>
            </details>
        @endif
    </section>

    @if(auth()->user()->mayAnywhere('read', 'agreement'))
        @include('description._relations', ['subject' => $accession, 'kind' => 'accessions', 'relations' => $relations, 'canUpdate' => $canUpdate,
            'heading' => 'Donor agreements', 'types' => ['documentation'], 'section' => 'ag'])
        @if(auth()->user()->may('create', 'agreement'))<p><a href="{{ route('agreements.create') }}">{{ __('New agreement') }}</a></p>@endif
    @endif

    @include('accessions._appraisal')
    @include('description._rights', ['subject' => $accession, 'kind' => 'accessions', 'canUpdate' => $canUpdate])

    @php($label = fn ($c) => $c?->label($lang))
    @foreach([
        'extents' => ['Extent statements', 'CAAIS 3.2', $accession->extents, fn ($r) => collect([$label($r->extentType), trim(($r->is_estimate ? 'ca. ' : '').($r->quantity === null ? '' : rtrim(rtrim((string) $r->quantity, '0'), '.')).' '.$label($r->unit)), $label($r->contentType), $label($r->carrierType), $r->digital_file_formats, $r->note])],
        'languages' => ['Language of material', 'CAAIS 3.4', $accession->languages, fn ($r) => collect([$r->language ? \App\Description\IsoCodes::names('language', $r->language) : null, $r->note])],
        'preservation' => ['Preservation requirements', 'CAAIS 4.3', $accession->preservationRequirements, fn ($r) => collect([$label($r->requirementType), $r->requirement, $r->note])],
        'events' => ['Transfer and accessioning events', 'CAAIS 5.1', $accession->events, fn ($r) => collect([$label($r->eventType), $r->date?->format('Y-m-d'), $r->agent, $r->note])],
    ] as $rowKind => [$heading, $number, $rows, $summary])
        <section id="caais-{{ $rowKind }}">
            <h2>{{ __($heading) }} ({{ $number }})</h2>
            @forelse($rows as $row)
                <p style="margin: .2rem 0">{{ $summary($row)->filter(fn ($v) => $v !== null && $v !== '')->join(', ') }}
                    @if($canUpdate)<form method="post" action="{{ route('accessions.rows.destroy', [$accession, $rowKind, $row->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
            @empty
                <p>{{ __('None yet.') }}</p>
            @endforelse
            @if($canUpdate)
                <details>
                    <summary>{{ __('Add') }}</summary>
                    <form method="post" action="{{ route('accessions.rows.store', [$accession, $rowKind]) }}">
                        @csrf
                        @if($rowKind === 'extents')
                            @foreach(['extent_type' => 'Extent type', 'unit' => 'Unit', 'content_type' => 'Content type', 'carrier_type' => 'Carrier type'] as $field => $fieldLabel)
                                <label for="x-{{ $field }}">{{ __($fieldLabel) }}</label>
                                <select id="x-{{ $field }}" name="row[{{ $field }}]" style="font: inherit; padding: .5rem">@if($field !== 'extent_type')<option value="">{{ __('Not set') }}</option>@endif @foreach($choices[$field] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                            @endforeach
                            <label for="x-quantity">{{ __('Quantity') }}</label><input id="x-quantity" name="row[quantity]" inputmode="decimal">
                            <label style="font-weight: normal"><input type="checkbox" name="row[is_estimate]" value="1" style="width: auto"> {{ __('ca. (estimate)') }}</label>
                            <label for="x-formats">{{ __('Digital file formats') }}</label><input id="x-formats" name="row[digital_file_formats]">
                        @elseif($rowKind === 'languages')
                            <label for="l-language">{{ __('Language') }}</label>
                            <select id="l-language" name="row[language]" style="font: inherit; padding: .5rem"><option value="">{{ __('Not set') }}</option>@foreach($choices['languages'] as $code => $name)<option value="{{ $code }}">{{ $name }} ({{ $code }})</option>@endforeach</select>
                        @elseif($rowKind === 'preservation')
                            <label for="p-type">{{ __('Type') }}</label>
                            <select id="p-type" name="row[requirement_type]" style="font: inherit; padding: .5rem">@foreach($choices['requirement_type'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                            <label for="p-requirement">{{ __('Requirement') }}</label><textarea id="p-requirement" name="row[requirement]" rows="2" style="width: 100%; font: inherit"></textarea>
                        @else
                            <label for="e-type">{{ __('Event type') }}</label>
                            <select id="e-type" name="row[event_type]" style="font: inherit; padding: .5rem">@foreach($choices['event_type'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                            <label for="e-date">{{ __('Date') }}</label><input id="e-date" name="row[date]" placeholder="YYYY-MM-DD" maxlength="10">
                            <label for="e-agent">{{ __('Agent') }}</label><input id="e-agent" name="row[agent]" maxlength="255">
                        @endif
                        <label for="{{ $rowKind }}-note">{{ __($rowKind === 'languages' ? 'Statement' : 'Note') }}</label><textarea id="{{ $rowKind }}-note" name="row[note]" rows="2" style="width: 100%; font: inherit"></textarea>
                        <button type="submit">{{ __('Add') }}</button>
                    </form>
                </details>
            @endif
        </section>
    @endforeach

    <section id="deaccessions">
        <h2>{{ __('Deaccessions') }}</h2>
        @forelse($accession->deaccessions as $d)
            <p style="margin: .2rem 0"><strong>{{ $d->identifier }}</strong>, {{ $d->scope->label($lang) }}@if($d->date), {{ $d->date->format('Y-m-d') }}@endif
                @if($canUpdate)<form method="post" action="{{ route('accessions.deaccessions.destroy', [$accession, $d->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
            @foreach(['Description' => $d->description, 'Extent' => $d->extent, 'Reason' => $d->reason] as $what => $text)@if($text)<p style="margin: 0 0 .4rem; white-space: pre-line"><em>{{ __($what) }}:</em> {{ $text }}</p>@endif @endforeach
        @empty
            <p>{{ __('None.') }}</p>
        @endforelse
        @if($canUpdate)
            <details>
                <summary>{{ __('Deaccession') }}</summary>
                <form method="post" action="{{ route('accessions.deaccessions.store', $accession) }}">
                    @csrf
                    <label for="da-identifier">{{ __('Identifier') }}</label><input id="da-identifier" name="identifier" required maxlength="255">
                    <label for="da-scope">{{ __('Scope') }}</label>
                    <select id="da-scope" name="scope" style="font: inherit; padding: .5rem">@foreach($choices['scope'] as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
                    <label for="da-date">{{ __('Date') }}</label><input id="da-date" name="date" placeholder="YYYY-MM-DD" maxlength="10">
                    @foreach(['description' => 'Description', 'extent' => 'Extent', 'reason' => 'Reason'] as $field => $fieldLabel)
                        <label for="da-{{ $field }}">{{ __($fieldLabel) }}</label><textarea id="da-{{ $field }}" name="{{ $field }}" rows="2" style="width: 100%; font: inherit"></textarea>
                    @endforeach
                    <label for="da-language">{{ __('Language (BCP 47)') }}</label><input id="da-language" name="language" required value="{{ app()->getLocale() }}">
                    <button type="submit">{{ __('Deaccession') }}</button>
                </form>
            </details>
        @endif
    </section>

    @include('description._identifiers', ['subject' => $accession, 'kind' => 'accessions', 'canUpdate' => $canUpdate])

    <h2>{{ __('Date of creation or revision') }} (CAAIS 7.2)</h2>
    @forelse($revisions as $revision)
        <p style="margin: .2rem 0">{{ __(ucfirst($revision['type'])) }}: {{ $revision['date'] }}@if($revision['agent']), {{ $revision['agent'] }}@endif</p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    <p class="meta">{{ __('Creation and revision dates and agents (CAAIS 7.2) are recorded automatically on every save.') }}</p>
@endsection
