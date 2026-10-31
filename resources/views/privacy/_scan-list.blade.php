{{-- OpenRiC Workspace - a list of scans for personal information, each the latest of its record (roadmap phase 18b). Needs: $reports, $lang. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@if($reports->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Scanned') }}</th><th scope="col">{{ __('Under') }}</th><th scope="col">{{ __('Found') }}</th><th scope="col">{{ __('Read') }}</th></tr></thead>
        <tbody>@foreach($reports as $r)<tr><td><a href="{{ route('records.show', $r->record) }}#privacy">{{ \App\Relations\EntityKinds::label($r->record) }}</a></td><td>{{ $r->started_at->format('Y-m-d') }}</td>
            <td>{{ $r->jurisdiction->name }}</td><td>{{ collect((array) $r->hits_by_type)->map(fn ($n, $t) => "$t $n")->join(', ') ?: 0 }}</td><td>{{ $r->coverage->label($lang) }}</td></tr>@endforeach</tbody></table>
@endif
