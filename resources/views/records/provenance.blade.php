{{-- OpenRiC Workspace - the provenance and custody history of a unit of description: its overview, the chain of steps, the timeline and the supporting documents (Heratio's provenance page; PSIS's ahgProvenancePlugin). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Provenance').': '.$record->described('title', ['en']))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($label = fn ($c) => $c?->label($lang))
    @php($select = function (string $name, ?string $current) use ($choices, $lang) {
        $html = '<select id="ov-'.e($name).'" name="'.e($name).'" style="font: inherit; padding: .5rem"><option value="">'.e(__('Not set')).'</option>';
        foreach ($choices[$name] as $c) {
            $html .= '<option value="'.e($c->notation).'"'.($current === $c->notation ? ' selected' : '').'>'.e($c->label($lang)).'</option>';
        }

        return new \Illuminate\Support\HtmlString($html.'</select>');
    })
    <p><a href="{{ route('records.show', $record) }}">{{ __('Back to the description') }}</a></p>
    <h1>{{ __('Provenance') }}: {{ $record->described('title', [app()->getLocale(), 'en']) }}</h1>
    <p>{{ __('Its ownership and custody history. Each step is a rico:Activity that affects the record, with its owner or holder taking part.') }} @include('description._ric', ['term' => 'rico:isOrWasAffectedBy'])
        <a href="{{ route('records.provenance.csv', $record) }}">{{ __('Export CSV') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <section id="overview">
        <h2>{{ __('Provenance Statement') }}</h2>
        @if($overview)
            @if($overview->summary)<p style="white-space: pre-line">{{ $overview->summary }}</p>@endif
            <p>{{ collect([
                $overview->currentStatus ? __('Current Status').': '.$label($overview->currentStatus) : null,
                $overview->custodyType ? __('Custody Type').': '.$label($overview->custodyType) : null,
                $overview->acquisitionType ? __('Acquisition Type').': '.$label($overview->acquisitionType) : null,
                $overview->acquisition_date || $overview->acquisition_date_text ? __('Acquisition Date').': '.($overview->acquisition_date_text ?? $overview->acquisition_date->format('Y-m-d')) : null,
                $overview->acquisition_price !== null ? __('Price').': '.$overview->acquisition_currency.' '.number_format((float) $overview->acquisition_price, 2) : null,
                $overview->certainty ? __('Certainty Level').': '.$label($overview->certainty) : null,
                $overview->researchStatus ? __('Research Status').': '.$label($overview->researchStatus) : null,
                $overview->culturalPropertyStatus ? __('Cultural property').': '.$label($overview->culturalPropertyStatus) : null,
                $overview->nazi_era_checked ? __('Nazi-era provenance checked').': '.($overview->nazi_era_clear === null ? __('result not recorded') : ($overview->nazi_era_clear ? __('clear') : __('not clear'))) : null,
                $overview->has_gaps ? __('There are gaps in the provenance chain') : null,
                __($overview->is_complete ? 'Research is complete' : 'Research is not complete'),
                __($overview->is_public ? 'Shown publicly' : 'Not shown publicly'),
            ])->filter()->join('; ') }}</p>
        @else
            <p>{{ __('No overview yet.') }}</p>
        @endif
        @if($canUpdate)
            <details>
                <summary>{{ __('Edit the overview') }}</summary>
                <form method="post" action="{{ route('records.provenance.overview', $record) }}">
                    @csrf
                    <label for="ov-summary">{{ __('Provenance Statement') }}</label><textarea id="ov-summary" name="summary" rows="4" style="width: 100%; font: inherit">{{ old('summary', $overview?->summary) }}</textarea>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 0 1rem">
                        <div><label for="ov-acquisition_type">{{ __('Acquisition Type') }}</label>{{ $select('acquisition_type', $overview?->acquisitionType?->notation) }}</div>
                        <div><label for="ov-acquisition_date">{{ __('Acquisition Date') }}</label><input id="ov-acquisition_date" name="acquisition_date" type="date" value="{{ $overview?->acquisition_date?->format('Y-m-d') }}"></div>
                        <div><label for="ov-acquisition_date_text">{{ __('Date (text)') }}</label><input id="ov-acquisition_date_text" name="acquisition_date_text" maxlength="255" value="{{ $overview?->acquisition_date_text }}" placeholder="{{ __('such as circa 1950') }}"></div>
                        <div><label for="ov-acquisition_price">{{ __('Price') }}</label><input id="ov-acquisition_price" name="acquisition_price" inputmode="decimal" value="{{ $overview?->acquisition_price }}"></div>
                        <div><label for="ov-acquisition_currency">{{ __('Currency') }}</label><input id="ov-acquisition_currency" name="acquisition_currency" maxlength="3" value="{{ $overview?->acquisition_currency }}" placeholder="ZAR"></div>
                        <div><label for="ov-current_status">{{ __('Current Status') }}</label>{{ $select('current_status', $overview?->currentStatus?->notation) }}</div>
                        <div><label for="ov-custody_type">{{ __('Custody Type') }}</label>{{ $select('custody_type', $overview?->custodyType?->notation) }}</div>
                        <div><label for="ov-certainty">{{ __('Certainty Level') }}</label>{{ $select('certainty', $overview?->certainty?->notation) }}</div>
                        <div><label for="ov-research_status">{{ __('Research Status') }}</label>{{ $select('research_status', $overview?->researchStatus?->notation) }}</div>
                    </div>
                    <label for="ov-research_notes">{{ __('Research Notes') }}</label><textarea id="ov-research_notes" name="research_notes" rows="2" style="width: 100%; font: inherit">{{ $overview?->research_notes }}</textarea>
                    @foreach(['has_gaps' => 'There are gaps in the provenance chain', 'is_complete' => 'Research is complete', 'is_public' => 'Display provenance publicly'] as $flag => $text)
                        <input type="hidden" name="{{ $flag }}" value="0">
                        <label style="font-weight: normal"><input type="checkbox" name="{{ $flag }}" value="1" @checked($overview ? $overview->{$flag} : $flag === 'is_public') style="width: auto"> {{ __($text) }}</label>
                    @endforeach
                    <label for="ov-gap_description">{{ __('Gap Description') }}</label><textarea id="ov-gap_description" name="gap_description" rows="2" style="width: 100%; font: inherit">{{ $overview?->gap_description }}</textarea>
                    <fieldset style="margin-top: 1rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
                        <legend>{{ __('Nazi-era provenance') }}</legend>
                        <input type="hidden" name="nazi_era_checked" value="0">
                        <label style="font-weight: normal"><input type="checkbox" name="nazi_era_checked" value="1" @checked($overview?->nazi_era_checked) style="width: auto"> {{ __('Nazi-era provenance has been checked') }}</label>
                        <label for="ov-nazi_era_clear">{{ __('Result') }}</label>
                        <select id="ov-nazi_era_clear" name="nazi_era_clear" style="font: inherit; padding: .5rem">
                            <option value="" @selected($overview?->nazi_era_clear === null)>{{ __('Not recorded') }}</option>
                            <option value="1" @selected($overview?->nazi_era_clear === true)>{{ __('Clear') }}</option>
                            <option value="0" @selected($overview?->nazi_era_clear === false)>{{ __('Not clear') }}</option>
                        </select>
                        <label for="ov-nazi_era_notes">{{ __('Notes') }}</label><textarea id="ov-nazi_era_notes" name="nazi_era_notes" rows="2" style="width: 100%; font: inherit">{{ $overview?->nazi_era_notes }}</textarea>
                    </fieldset>
                    <fieldset style="margin-top: 1rem; border: 1px solid var(--line); padding: 0 1rem 1rem">
                        <legend>{{ __('Cultural property') }}</legend>
                        <label for="ov-cultural_property_status">{{ __('Status') }}</label>{{ $select('cultural_property_status', $overview?->culturalPropertyStatus?->notation) }}
                        <label for="ov-cultural_property_notes">{{ __('Notes') }}</label><textarea id="ov-cultural_property_notes" name="cultural_property_notes" rows="2" style="width: 100%; font: inherit">{{ $overview?->cultural_property_notes }}</textarea>
                    </fieldset>
                    <button type="submit">{{ __('Save') }}</button>
                </form>
            </details>
        @endif
    </section>

    <section id="chain">
        <h2>{{ __('Ownership History') }}</h2>
        @forelse($steps as $step)
            <div id="step-{{ $step->id }}" style="border-top: 1px solid var(--line); padding: .4rem 0">
                <p style="margin: .2rem 0"><strong>{{ $step->position }}. {{ $step->owner_name }}</strong>@if($step->owner) (<a href="{{ route('agents.show', $step->owner) }}">{{ __('authority record') }}</a>)@endif
                    @if($step->ownerType), {{ $label($step->ownerType) }}@endif: {{ $label($step->transferType) }}, {{ $step->period($lang) }}
                    <br><small>{{ collect([
                        $step->owner_location ? __('Location').': '.$step->owner_location.($step->owner_location_tgn ? ' (TGN '.$step->owner_location_tgn.')' : '') : null,
                        $step->sale_price !== null ? __('Sale Price').': '.$step->sale_currency.' '.number_format((float) $step->sale_price, 2) : null,
                        $step->auction_house ? __('Auction House').': '.$step->auction_house.($step->auction_lot ? ', '.__('lot').' '.$step->auction_lot : '') : null,
                        $step->certainty ? __('Certainty').': '.$label($step->certainty) : null,
                        $step->evidenceType ? __('Evidence type').': '.$label($step->evidenceType) : null,
                    ])->filter()->join('; ') }}</small></p>
                @foreach(['transfer_details' => 'Transfer details', 'sources' => 'Sources/Documentation', 'evidence_description' => 'Evidence', 'notes' => 'Notes'] as $field => $text)
                    @if($step->{$field})<p style="margin: .1rem 0; white-space: pre-line"><em>{{ __($text) }}:</em> {{ $step->{$field} }}</p>@endif
                @endforeach
                @if($step->is_gap)<p style="margin: .1rem 0"><strong>{{ __('Gap in the provenance') }}</strong>@if($step->gap_explanation): {{ $step->gap_explanation }}@endif</p>@endif
                @if($canUpdate)
                    <div style="display: flex; gap: .4rem; flex-wrap: wrap">
                        @foreach(['earlier' => 'Move up', 'later' => 'Move down'] as $direction => $text)
                            @if(($direction === 'earlier' && ! $loop->parent->first) || ($direction === 'later' && ! $loop->parent->last))
                                <form method="post" action="{{ route('records.provenance.steps.move', [$record, $step->id]) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __($text) }}</button></form>
                            @endif
                        @endforeach
                        <form method="post" action="{{ route('records.provenance.steps.destroy', [$record, $step->id]) }}">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>
                    </div>
                    <details>
                        <summary>{{ __('Edit') }}</summary>
                        <form method="post" action="{{ route('records.provenance.steps.save', [$record, $step->id]) }}">
                            @csrf
                            @include('records._provenance-step-fields', ['step' => $step, 'p' => 'st'.$loop->index])
                            <button type="submit">{{ __('Save') }}</button>
                        </form>
                        <p class="meta">{{ __('Persistent IRI') }}: <code>{{ $step->iri }}</code></p>
                    </details>
                @endif
            </div>
        @empty
            <p>{{ __('No provenance entries yet.') }}</p>
        @endforelse
        @if($canUpdate)
            <details>
                <summary>{{ __('Add a provenance entry') }}</summary>
                <form method="post" action="{{ route('records.provenance.steps.save', $record) }}">
                    @csrf
                    @include('records._provenance-step-fields', ['step' => null, 'p' => 'st-new'])
                    <button type="submit">{{ __('Add') }}</button>
                </form>
            </details>
        @endif
    </section>

    <section id="timeline">
        <h2>{{ __('Timeline') }}</h2>
        @php($dated = $steps->filter(fn ($s) => $s->start_date || $s->end_date)->sortBy(fn ($s) => \App\Description\Edtf::bounds((string) ($s->start_date ?? $s->end_date))[0] ?? '9999'))
        @forelse($dated as $step)
            <p style="margin: .2rem 0"><strong>{{ $step->period($lang) }}</strong>: {{ $label($step->transferType) }}, {{ $step->owner_name }}@if($step->owner_location) ({{ $step->owner_location }})@endif</p>
        @empty
            <p>{{ __('No dated entries.') }}</p>
        @endforelse
    </section>

    <section id="documents">
        <h2>{{ __('Supporting documents') }}</h2>
        @forelse($record->provenanceDocuments as $d)
            <p style="margin: .2rem 0">
                @if($d->storage_path)<a href="{{ route('records.provenance.documents.show', [$record, $d->id]) }}">{{ $d->title ?? $d->original_name }}</a>@elseif($d->external_url)<a href="{{ $d->external_url }}" rel="noopener">{{ $d->title ?? $d->external_url }}</a>@else{{ $d->title ?? $d->archive_reference }}@endif
                ({{ collect([$label($d->documentType), $d->document_date_text ?? $d->document_date?->format('Y-m-d'), $d->archive_reference, $d->entry ? __('for :owner', ['owner' => $d->entry->owner_name]) : null, $d->is_public ? __('public') : null])->filter()->join(', ') }})
                @if($canUpdate)<form method="post" action="{{ route('records.provenance.documents.destroy', [$record, $d->id]) }}" style="display: inline">@csrf<button type="submit" style="margin-top: 0; padding: .1rem .5rem">{{ __('Delete') }}</button></form>@endif</p>
            @if($d->description)<p style="margin: 0 0 .4rem; white-space: pre-line">{{ $d->description }}</p>@endif
        @empty
            <p>{{ __('None yet.') }}</p>
        @endforelse
        @if($canUpdate)
            <details>
                <summary>{{ __('Add a supporting document') }}</summary>
                <form method="post" action="{{ route('records.provenance.documents.store', $record) }}" enctype="multipart/form-data">
                    @csrf
                    <label for="pd-type">{{ __('Document Type') }}</label>
                    <select id="pd-type" name="document_type" style="font: inherit; padding: .5rem">@foreach($choices['document_type'] as $c)<option value="{{ $c->notation }}" @selected($c->notation === 'other')>{{ $c->label($lang) }}</option>@endforeach</select>
                    <label for="pd-title">{{ __('Title') }}</label><input id="pd-title" name="title" maxlength="500">
                    <div style="display: flex; gap: .75rem; flex-wrap: wrap">
                        <div><label for="pd-date">{{ __('Date') }}</label><input id="pd-date" name="document_date" type="date"></div>
                        <div><label for="pd-date-text">{{ __('Date (text)') }}</label><input id="pd-date-text" name="document_date_text" maxlength="255"></div>
                    </div>
                    <label for="pd-file">{{ __('File Upload') }}</label><input id="pd-file" type="file" name="file">
                    <label for="pd-url">{{ __('Or External URL') }}</label><input id="pd-url" name="external_url" type="url" maxlength="1000">
                    <label for="pd-ref">{{ __('Or archive reference') }}</label><input id="pd-ref" name="archive_reference" maxlength="500">
                    <label for="pd-entry">{{ __('Provenance Entry') }}</label>
                    <select id="pd-entry" name="entry" style="font: inherit; padding: .5rem"><option value="">{{ __('The whole provenance') }}</option>@foreach($steps as $s)<option value="{{ $s->id }}">{{ $s->position }}. {{ $s->owner_name }}</option>@endforeach</select>
                    <label for="pd-description">{{ __('Description') }}</label><textarea id="pd-description" name="description" rows="2" style="width: 100%; font: inherit"></textarea>
                    <input type="hidden" name="is_public" value="0">
                    <label style="font-weight: normal"><input type="checkbox" name="is_public" value="1" style="width: auto"> {{ __('Public') }}</label>
                    <button type="submit">{{ __('Add') }}</button>
                </form>
            </details>
        @endif
    </section>
@endsection
