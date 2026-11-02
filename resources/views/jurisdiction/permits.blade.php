{{-- OpenRiC Workspace - applications for permits to export heritage objects under NHRA s.32 (PSIS's ahgSAHRAPlugin permit pages; roadmap phase 18d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Export permits'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Export permits') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A heritage object leaves South Africa only with a permit under section 32 of the National Heritage Resources Act. An application is approved within the institution, lodged with SAHRA or the provincial authority, and its outcome recorded here.') }}</p>
    <table><thead><tr><th scope="col">{{ __('Application') }}</th><th scope="col">{{ __('Purpose') }}</th><th scope="col">{{ __('Destination') }}</th><th scope="col">{{ __('Records') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($permits as $p)<tr><td><a href="{{ route('jurisdiction.permit', $p) }}">{{ $p->application_ref }}</a>@if($p->permit_number) · {{ $p->permit_number }}@endif</td><td>{{ $p->purpose }}</td>
            <td>{{ $p->destination_institution ?? '-' }}@if($p->destination_country) ({{ $p->destination_country }})@endif</td><td>{{ $p->records->count() }}</td>
            <td>{{ $p->status->label($lang) }}@if($p->end_date) · {{ __('until :date', ['date' => $p->end_date->format('Y-m-d')]) }}@endif</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('New application') }}</h2>
    <form method="post" action="{{ route('jurisdiction.permits.store') }}">@csrf @include('jurisdiction._permit-fields', ['p' => null])<button type="submit">{{ __('Save as draft') }}</button></form>
@endsection
