{{-- OpenRiC Workspace - a unit's DOI and its funding, on its page (Heratio's DOI record badge and actions; roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $record. --}}
@php($doi = \App\Models\RecordDoi::where('record_resource_id', $record->id)->with('state.labels')->first())
@php($funding = \App\Models\RecordFunding::where('record_resource_id', $record->id)->get())
@php($mayPublish = auth()->user()->may('publish', 'description', $record))
<details class="area" @if($doi) open @endif><summary>{{ __('DOI and funding') }}</summary>
    @if($doi)
        <p><a href="{{ $doi->url() }}">{{ $doi->doi }}</a> <span class="meta">{{ $doi->state->label(\App\Support\Vocab::languages()) }}@if($doi->test) · {{ __('test') }}@endif</span> · <a href="{{ route('dois.show', $doi) }}">{{ __('Its log') }}</a></p>
        @if($mayPublish)
            @foreach(array_filter(['update' => __('Send it again'), 'publish' => $doi->state->notation === 'draft' ? __('Make it findable') : null, 'verify' => __('Ask DataCite'), 'hide' => $doi->state->notation === 'findable' ? __('Hide it') : null]) as $step => $word)
                <form method="post" action="{{ route('records.doi', [$record, $step]) }}" style="display: inline">@csrf<button type="submit" style="margin: 0; padding: .1rem .6rem">{{ $word }}</button></form>
            @endforeach
        @endif
    @elseif($mayPublish)
        <p class="meta">{{ __('No DOI yet. A DOI publishes the unit, so it must be published first.') }}</p>
        @foreach(['mint' => __('Mint a DOI'), 'reserve' => __('Reserve one as a draft'), 'queue' => __('Queue it')] as $step => $word)
            <form method="post" action="{{ route('records.doi', [$record, $step]) }}" style="display: inline">@csrf<button type="submit" style="margin: 0; padding: .1rem .6rem">{{ $word }}</button></form>
        @endforeach
    @endif
    <h3>{{ __('Funding') }}</h3>
    <ul>@forelse($funding as $f)<li>{{ $f->funder_name }}@if($f->award_number), {{ $f->award_number }}@endif @if($f->award_title) <span class="meta">{{ $f->award_title }}</span>@endif
        @if(auth()->user()->may('update', 'description', $record))<form method="post" action="{{ route('records.funding.destroy', [$record, $f]) }}" style="display: inline">@csrf<button type="submit" style="margin: 0; padding: .1rem .5rem">{{ __('Remove') }}</button></form>@endif</li>
    @empty<li class="meta">{{ __('None.') }}</li>@endforelse</ul>
    @if(auth()->user()->may('update', 'description', $record))
        <details><summary>{{ __('Add funding') }}</summary><form method="post" action="{{ route('records.funding.store', $record) }}">@csrf
            <label for="fn">{{ __('Funder') }}</label><input id="fn" name="funder_name" required maxlength="500">
            <label for="fi">{{ __('Funder identifier') }}</label><input id="fi" name="funder_identifier" maxlength="500">
            <label for="ft">{{ __('Its scheme') }}</label><select id="ft" name="funder_identifier_type"><option value="">-</option>@foreach(['ror' => 'ROR', 'crossref-funder-id' => 'Crossref Funder ID', 'isni' => 'ISNI', 'grid' => 'GRID', 'other' => __('Other')] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <label for="fa">{{ __('Award number') }}</label><input id="fa" name="award_number" maxlength="255">
            <label for="fu">{{ __('Award address') }}</label><input id="fu" name="award_uri" type="url" maxlength="1000">
            <label for="fw">{{ __('Award title') }}</label><input id="fw" name="award_title" maxlength="1000">
            <button type="submit">{{ __('Add') }}</button></form></details>
    @endif
</details>
