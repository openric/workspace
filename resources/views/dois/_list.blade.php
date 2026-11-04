{{-- OpenRiC Workspace - a list of DOIs (roadmap phase 20c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<table><thead><tr><th scope="col">{{ __('DOI') }}</th><th scope="col">{{ __('Unit of description') }}</th><th scope="col">{{ __('State') }}</th><th scope="col">{{ __('Minted') }}</th></tr></thead>
    <tbody>@forelse($dois as $d)<tr><td><a href="{{ route('dois.show', $d) }}">{{ $d->doi }}</a>@if($d->test) <span class="meta">{{ __('test') }}</span>@endif</td>
        <td>@if($d->record)<a href="{{ route('records.show', $d->record) }}">{{ \App\Relations\EntityKinds::label($d->record) }}</a>@endif</td><td>{{ $d->state->label($lang) }}</td><td>{{ $d->minted_at?->format('Y-m-d') }}</td></tr>
    @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
