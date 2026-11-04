{{-- OpenRiC Workspace - DoiMail body (roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
@if($doi)
    <p>{{ __(':title now has the DOI :doi, which resolves to its persistent IRI.', ['title' => \App\Relations\EntityKinds::label($unit), 'doi' => $doi->doi]) }}@if($doi->test) {{ __('It was registered with DataCite\'s test service, so it does not resolve at doi.org.') }}@endif</p>
    <p><a href="{{ $doi->url() }}">{{ $doi->url() }}</a></p>
@else
    <p>{{ __('DataCite did not register a DOI for :title: :error', ['title' => \App\Relations\EntityKinds::label($unit), 'error' => $error]) }}</p>
@endif
<p><a href="{{ route('records.show', $unit) }}">{{ __('Open the record') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
