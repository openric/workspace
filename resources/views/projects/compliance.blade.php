{{-- OpenRiC Workspace - what a research project still owes its funders, committees and readers, its replication pack and its DOIs (Heratio's compliance dashboard, roCrate, reproducibility pack and mintDoi; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Compliance').' - '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    @include('projects._part-head')
    <h1>{{ __('Compliance') }}</h1>
    @php($names = ['ethics' => __('Ethics approvals'), 'dmp' => __('Data management plan'), 'funding' => __('Funding'), 'identifiers' => __('Persistent identifiers'), 'orcid' => __('ORCID iDs'), 'replication' => __('Replication pack')])
    @php($states = ['ok' => __('In order'), 'attention' => __('Needs attention'), 'missing' => __('Missing')])
    <table>
        <thead><tr><th scope="col">{{ __('Check') }}</th><th scope="col">{{ __('State') }}</th><th scope="col">{{ __('What to do') }}</th></tr></thead>
        <tbody>@foreach($compliance['checks'] as $key => [$state, $text])<tr><th scope="row">{{ $names[$key] }}</th><td>@if($state === 'ok'){{ $states[$state] }}@else<strong>{{ $states[$state] }}</strong>@endif</td><td>{{ $text }}</td></tr>@endforeach</tbody>
    </table>

    <h2>{{ __('Replication pack') }}</h2>
    <p>{{ __('An RO-Crate: a ZIP whose ro-crate-metadata.json describes every file in it. Data and code are named by where they are, not carried.') }}</p>
    <ul>@foreach($pack['included'] as $name => $i)<li>{{ $name }}: {{ $i['what'] }} ({{ $i['count'] }})</li>@endforeach</ul>
    @if($pack['omitted'])<p>{{ __('Not included:') }}</p><ul>@foreach($pack['omitted'] as $o)<li>{{ $o }}</li>@endforeach</ul>@endif
    <form method="post" action="{{ route('research.replication', $project) }}">@csrf<button type="submit">{{ __('Build and download the pack') }}</button></form>
    @if($builds->isNotEmpty())<p class="meta">{{ __('Built before') }}:</p><ul class="meta">@foreach($builds as $b)<li>{{ $b->built_at->timezone($tz)->format('j M Y H:i') }} {{ __('by :name', ['name' => $b->builtBy?->name ?? '-']) }} · SHA-256 <code>{{ $b->sha256 }}</code> · {{ number_format($b->size_bytes / 1024, 1) }} KB</li>@endforeach</ul>@endif

    <h2>{{ __('DOIs') }}</h2>
    @forelse($dois as $d)
        <p><a href="https://doi.org/{{ $d->doi }}" rel="noopener noreferrer">{{ $d->doi }}</a> · {{ $d->output?->title ?? __('The project') }} · {{ $d->state->label($lang) }}@if($d->test) · {{ __('DataCite test service') }}@endif
            · <a href="{{ route('research.dois.landing', $d) }}">{{ __('Landing page') }}</a>
            @if($level >= 3)<form method="post" action="{{ route('research.dois.publish', $d) }}" style="display: inline">@csrf<button type="submit">{{ $d->state->notation === 'draft' ? __('Make findable') : __('Bring its metadata up to date') }}</button></form>@endif</p>
    @empty
        <p>{{ __('No DOI is registered for the project or its outputs.') }}</p>
    @endforelse
    @if($level >= 3)
        @if(! $datacite)
            <p>{{ __('DataCite is not set up yet. Ask the institution to set it up under the research settings.') }}</p>
        @elseif($project->visibility->notation !== 'public')
            <p>{{ __('Make the project public first: registering a DOI publishes it.') }}</p>
        @else
            <form method="post" action="{{ route('research.dois.store', $project) }}">@csrf
                <label for="d-output">{{ __('Register a DOI for') }}</label> <select id="d-output" name="output_id"><option value="">{{ __('The project') }}</option>@foreach($outputs as $o)@unless($dois->contains('output_id', $o->id))<option value="{{ $o->id }}">{{ $o->title }} ({{ $o->outputType->label($lang) }})</option>@endunless @endforeach</select>
                <input type="hidden" name="publish" value="0"><label><input type="checkbox" name="publish" value="1"> {{ __('Findable at once (cannot be undone)') }}</label>
                <button type="submit">{{ __('Register') }}</button>@if($datacite->datacite_test) <span class="meta">{{ __('DataCite test service') }}</span>@endif</form>
        @endif
    @endif
@endsection
