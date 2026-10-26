{{-- OpenRiC Workspace - the instantiations of a unit: its digital files and other carriers (rico:hasOrHadInstantiation, WS-007). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $record (with instantiations.files.use and instantiations.names loaded) and $canUpdate. --}}
@php($service = app(\App\Description\InstantiationService::class))
<h2 id="instantiations">{{ __('Instantiations: digital files and other carriers') }} @include('description._ric', ['term' => 'rico:hasOrHadInstantiation'])</h2>
@forelse($record->instantiations as $instantiation)
    @php($thumbnail = $instantiation->file('thumbnail'))
    @php($master = $instantiation->file('master'))
    <div style="display: flex; gap: 1rem; align-items: flex-start; margin: .75rem 0">
        @if($thumbnail && $service->deliverable(auth()->user(), $thumbnail))
            <img src="{{ route('files.show', $thumbnail) }}" alt="" width="{{ $thumbnail->width }}" height="{{ $thumbnail->height }}" style="max-width: 120px; height: auto; border: 1px solid var(--line)">
        @endif
        <p style="margin: 0; min-width: 0; overflow-wrap: anywhere"><a href="{{ route('instantiations.show', $instantiation) }}">{{ $instantiation->described('instantiation-title', [app()->getLocale(), 'en']) }}</a>
            <br><small>{{ collect([$instantiation->carrierType?->label(['en']), $master ? $master->mime_type.', '.\Illuminate\Support\Number::fileSize($master->size_bytes) : null,
                $instantiation->availability->label(['en'])])->filter()->join(' · ') }}
            @if($master?->fixity_failed_at)<strong role="alert"> {{ __('· fixity failed') }}</strong>@endif</small></p>
    </div>
@empty
    <p>{{ __('None yet.') }}</p>
@endforelse
@if($canUpdate)
    <details>
        <summary>{{ __('Upload a file, or add another carrier') }}</summary>
        <form method="post" action="{{ route('instantiations.store', $record) }}" enctype="multipart/form-data">
            @csrf
            <label for="i-file">{{ __('File (up to :size MB)', ['size' => config('workspace.max_upload_megabytes')]) }}</label>
            <input id="i-file" type="file" name="file" aria-describedby="i-file-help">
            <small id="i-file-help">{{ __('Its SHA-256 is taken as it arrives and it is scanned for viruses. Images and PDFs get a reference copy and a thumbnail; the master is kept as received. Leave this empty to record a carrier with no file, such as the paper original.') }}</small>
            <label for="i-sha">{{ __('SHA-256 you expect (optional)') }}</label> @include('description._ric', ['term' => 'openricx:checksumValue'])
            <input id="i-sha" name="sha256" maxlength="64" pattern="[0-9a-fA-F]{64}" aria-describedby="i-sha-help">
            <small id="i-sha-help">{{ __('From the scanner or the depositor. A file that does not match is refused, so damage on the way is caught.') }}</small>
            <label for="i-title">{{ __('Title (the file\'s name if left empty)') }}</label> @include('description._ric', ['term' => 'rico:title'])
            <input id="i-title" name="title" maxlength="2000">
            <label for="i-language">{{ __('Language of the title (BCP 47)') }}</label> @include('description._ric', ['term' => 'xml:lang'])
            <input id="i-language" name="language" value="en" required maxlength="35">
            <label for="i-carrier">{{ __('Carrier type, for a carrier with no file') }}</label> @include('description._ric', ['term' => 'rico:hasCarrierType'])
            <select id="i-carrier" name="carrier_type">
                <option value="">{{ __('Not set') }}</option>
                @foreach(\App\Models\Concept::choices(\App\Description\InstantiationService::CARRIER_TYPES)->get() as $type)<option value="{{ $type->notation }}">{{ $type->label(['en']) }}</option>@endforeach
            </select>
            <label for="i-availability">{{ __('Availability') }}</label> @include('description._ric', ['term' => 'rico:conditionsOfAccess'])
            <select id="i-availability" name="availability">
                @foreach(\App\Models\Concept::choices(\App\Description\InstantiationService::AVAILABILITY)->get() as $state)<option value="{{ $state->notation }}" title="{{ $state->labels->firstWhere('language', 'en')?->definition }}">{{ $state->label(['en']) }}</option>@endforeach
            </select>
            <button type="submit">{{ __('Add') }}</button>
        </form>
    </details>
@endif
