{{-- OpenRiC Workspace - register an accession, with Heratio's add form: basic info, the donor and their primary contact, the administration area, the descriptions made from it and the CAAIS elements. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Add new accession record'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($agentName = fn ($a) => $a->described('agent-authorised-name', $lang))
    <p><a href="{{ route('accessions.index') }}">{{ __('Back') }}</a></p>
    <h1>{{ __('Add new accession record') }} @include('description._ric', ['term' => 'rico:Activity'])</h1>
    <p>{{ __('Fill in what you know; empty fields are left out. Extents, languages, preservation requirements and more dates and events are added on the accession\'s page.') }}</p>
    <form method="post" action="{{ route('accessions.store') }}">
        @csrf
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <label for="language">{{ __('Language of these values (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
        <input id="language" name="language" required value="{{ old('language', app()->getLocale()) }}">

        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Basic info') }}</strong></legend>
            <label for="number">{{ __('Accession number') }} (CAAIS 1.2)</label> @include('description._ric', ['term' => 'rico:Identifier'])
            <input id="number" name="number" maxlength="255" value="{{ old('number') }}" aria-describedby="number-help">
            <small id="number-help">{{ __('Unique in the institution, such as 2026-014. Leave it empty for the next number of the institution\'s numbering.') }}</small>
            <label for="identifier_type">{{ __('Alternative identifier(s)') }}</label> @include('description._ric', ['term' => 'rico:Identifier'])
            <div style="display: flex; gap: .5rem; flex-wrap: wrap">
                <select id="identifier_type" name="identifier_type" aria-label="{{ __('Type') }}" style="font: inherit; padding: .5rem">@foreach($identifierTypes as $t)<option value="{{ $t->notation }}" @selected(old('identifier_type', 'other') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select>
                <input name="identifier_value" aria-label="{{ __('Identifier') }}" maxlength="255" value="{{ old('identifier_value') }}" style="flex: 1; min-width: 12rem">
            </div>
            <label for="acquisition_date">{{ __('Acquisition date') }} (CAAIS 3.1)</label> @include('description._ric', ['term' => 'rico:date'])
            <small id="acquisition-help">{{ __('Accession date represents the date of receipt of the materials and is added during the donation process.') }}</small>
            <input id="acquisition_date" name="acquisition_date" type="date" aria-describedby="acquisition-help" value="{{ old('acquisition_date') }}">
            <label for="repository">{{ __('Repository') }} (CAAIS 1.1)</label> @include('description._ric', ['term' => null])
            <select id="repository" name="repository" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Not set') }}</option>
                @foreach($repositories as $r)<option value="{{ $r->id }}" @selected(old('repository') === $r->id)>{{ $r->described('authorised-name', [app()->getLocale(), 'en']) }}</option>@endforeach
            </select>
        </fieldset>

        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Donor/Transferring body area') }}</strong></legend>
            <label for="donor">{{ __('Related donor record') }}</label> @include('description._ric', ['term' => 'rico:hasOrHadParticipant'])
            {{-- ponytail: every authority record in one list, as the rights holder; a search field when institutions hold more than a few thousand. --}}
            <select id="donor" name="donor" style="font: inherit; padding: .5rem"><option value="">{{ __('A new donor, below') }}</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(old('donor') === $a->id)>{{ $agentName($a) }}</option>@endforeach
            </select>
            <div style="display: flex; gap: .5rem; flex-wrap: wrap">
                <div style="flex: 1; min-width: 14rem"><label for="donor_name">{{ __('Name') }}</label><input id="donor_name" name="donor_name" maxlength="2000" value="{{ old('donor_name') }}"></div>
                <div><label for="donor_type">{{ __('Entity type') }}</label>
                    <select id="donor_type" name="donor_type" style="font: inherit; padding: .5rem">@foreach($agentTypes as $t)<option value="{{ $t->notation }}" @selected(old('donor_type', 'person') === $t->notation)>{{ $t->label($lang) }}</option>@endforeach</select></div>
            </div>
            <h3 style="font-size: 1rem">{{ __('Primary contact information') }}</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0 1rem">
                @foreach(['contact_person' => 'Contact person', 'telephone' => 'Telephone', 'fax' => 'Fax', 'email' => 'Email', 'website' => 'URL', 'street_address' => 'Street address',
                    'region' => 'Region/province', 'country_code' => 'Country (two-letter code, such as ZA)', 'postal_code' => 'Postal code', 'city' => 'City', 'latitude' => 'Latitude',
                    'longitude' => 'Longitude', 'label' => 'Contact type'] as $field => $text)
                    <div><label for="dc-{{ $field }}">{{ __($text) }}</label><input id="dc-{{ $field }}" name="donor_contact[{{ $field }}]" value="{{ old('donor_contact.'.$field) }}"></div>
                @endforeach
            </div>
            <label for="dc-note">{{ __('Note') }}</label><textarea id="dc-note" name="donor_contact[note]" rows="2" style="width: 100%; font: inherit">{{ old('donor_contact.note') }}</textarea>
        </fieldset>

        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Administration area') }}</strong></legend>
            @foreach(['acquisition_type' => ['Acquisition type', 'CAAIS 1.4'], 'resource_type' => ['Resource type', null], 'processing_status' => ['Processing status', 'CAAIS 1.6'], 'processing_priority' => ['Processing priority', null]] as $field => [$text, $number])
                <label for="{{ $field }}">{{ __($text) }}@if($number) ({{ $number }})@endif</label> @include('description._ric', ['term' => null])
                <select id="{{ $field }}" name="{{ $field }}" style="font: inherit; padding: .5rem">
                    <option value="">{{ __('Not set') }}</option>
                    @foreach($terms[$field] as $t)<option value="{{ $t->notation }}" @selected(old($field) === $t->notation)>{{ $t->label($lang) }}</option>@endforeach
                </select>
            @endforeach
            <label for="creators">{{ __('Creators') }}</label> @include('description._ric', ['term' => 'rico:hasOrHadParticipant'])
            <small id="creators-help">{{ __('The creators of the material it brought in. Hold Ctrl or Cmd to choose more than one.') }}</small>
            <select id="creators" name="creators[]" multiple size="5" aria-describedby="creators-help" style="width: 100%">
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(in_array($a->id, old('creators', []), true))>{{ $agentName($a) }}</option>@endforeach
            </select>
            <h3 style="font-size: 1rem">{{ __('Date(s)') }} (CAAIS 3.1)</h3>
            <div style="display: flex; gap: .5rem; flex-wrap: wrap">
                <div><label for="material_property">{{ __('Type') }}</label>
                    <select id="material_property" name="material_property" style="font: inherit; padding: .5rem">@foreach($materialProperties as $p)<option value="{{ $p->notation }}" @selected(old('material_property') === $p->notation)>{{ $p->label($lang) }}</option>@endforeach</select></div>
                <div style="flex: 1; min-width: 10rem"><label for="material_expressed">{{ __('Date') }}</label><input id="material_expressed" name="material_expressed" maxlength="255" value="{{ old('material_expressed') }}" placeholder="{{ __('such as ca. 1950-1965') }}"></div>
                <div><label for="material_start">{{ __('Start') }}</label><input id="material_start" name="material_start" maxlength="10" placeholder="YYYY-MM-DD" value="{{ old('material_start') }}" style="max-width: 9rem"></div>
                <div><label for="material_end">{{ __('End') }}</label><input id="material_end" name="material_end" maxlength="10" placeholder="YYYY-MM-DD" value="{{ old('material_end') }}" style="max-width: 9rem"></div>
            </div>
            <h3 style="font-size: 1rem">{{ __('Event(s)') }} (CAAIS 5.1)</h3>
            <div style="display: flex; gap: .5rem; flex-wrap: wrap">
                <div><label for="event-type">{{ __('Event type') }}</label>
                    <select id="event-type" name="event[event_type]" style="font: inherit; padding: .5rem"><option value="">{{ __('Not set') }}</option>@foreach($events as $e)<option value="{{ $e->notation }}" @selected(old('event.event_type') === $e->notation)>{{ $e->label($lang) }}</option>@endforeach</select></div>
                <div><label for="event-date">{{ __('Date') }}</label><input id="event-date" name="event[date]" maxlength="10" placeholder="YYYY-MM-DD" value="{{ old('event.date') }}" style="max-width: 9rem"></div>
                <div style="flex: 1; min-width: 10rem"><label for="event-agent">{{ __('Agent') }}</label><input id="event-agent" name="event[agent]" maxlength="255" value="{{ old('event.agent') }}"></div>
            </div>
            <label for="event-note">{{ __('Note') }}</label><textarea id="event-note" name="event[note]" rows="2" style="width: 100%; font: inherit">{{ old('event.note') }}</textarea>
        </fieldset>

        @include('description._fields', ['subject' => $accession, 'standard' => \App\Description\Elements::ACCESSION, 'language' => old('language', app()->getLocale())])

        <fieldset style="margin-top: 1.5rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
            <legend><strong>{{ __('Archival description') }}</strong></legend>
            <label for="records">{{ __('Archival descriptions made from it') }}</label> @include('description._ric', ['term' => 'rico:resultsOrResultedIn'])
            <select id="records" name="records[]" multiple size="5" style="width: 100%">
                @foreach($records as $r)<option value="{{ $r->id }}" @selected(in_array($r->id, old('records', []), true))>{{ $r->described('title', $lang) }}</option>@endforeach
            </select>
        </fieldset>
        <button type="submit">{{ __('Create accession') }}</button>
    </form>
@endsection
