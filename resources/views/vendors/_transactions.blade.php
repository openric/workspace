{{-- OpenRiC Workspace - a table of vendor transactions. Needs $transactions and $none. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = \App\Support\Vocab::languages())
<table><thead><tr><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Vendor') }}</th><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Requested') }}</th><th scope="col">{{ __('Due back') }}</th></tr></thead>
    <tbody>@forelse($transactions as $t)<tr><td><a href="{{ route('vendors.transaction', $t) }}">{{ $t->transaction_number }}</a></td><td>{{ \App\Relations\EntityKinds::label($t->vendor->agent) }}</td>
        <td>{{ $t->serviceType->label($lang) }}</td><td>{{ $t->status->label($lang) }}</td><td>{{ $t->request_date->format('Y-m-d') }}</td>
        <td>{{ $t->expected_return_date?->format('Y-m-d') }}@if($t->overdue()) <strong>{{ __('overdue') }}</strong>@endif</td></tr>
    @empty<tr><td colspan="6">{{ $none }}</td></tr>@endforelse</tbody></table>
