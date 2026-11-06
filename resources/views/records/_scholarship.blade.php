{{-- OpenRiC Workspace - a record's discovery and research lead (roadmap phase 17d4). Needs: $record. Shown to who may publish descriptions. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($discovery = \App\Models\ScholarshipDiscovery::where('record_resource_id', $record->id)->first())
<section id="scholarship">
    <h2>{{ __('Discoveries') }} @include('description._ric', ['term' => null])</h2>
    @if($discovery)<p>{{ __(\App\Scholarship\Scholarship::band($discovery->confidence)['label']) }} · {{ trans_choice(':count connection|:count connections', $discovery->connection_count, ['count' => $discovery->connection_count]) }}</p>@else<p>{{ __('No discovery yet.') }}</p>@endif
    <p><a href="{{ route('scholarship.show', $record) }}">{{ __('Connections and research leads') }}</a></p>
</section>
