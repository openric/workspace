{{-- OpenRiC Workspace - the valuation report: each accession's current value, totalled by currency and counted by type (PSIS's and Heratio's valuation report, for GRAP 103 and IPSAS 45). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Valuation report'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('accessions.index') }}">{{ __('Accessions') }}</a></p>
    <h1>{{ __('Valuation report') }}</h1>
    <p>{{ __('The current value of each accession valued: its latest valuation, for GRAP 103 and IPSAS 45.') }}</p>
    <p><strong>{{ __('Accessions valued') }}:</strong> {{ $count }}
        @foreach($by_currency as $currency => $total)<br><strong>{{ __('Total in :currency', ['currency' => $currency]) }}:</strong> {{ $currency }} {{ number_format($total, 2) }}@endforeach
        @if($by_type)<br><strong>{{ __('By type') }}:</strong> {{ collect($by_type)->map(fn ($n, $type) => "$type $n")->join(', ') }}@endif</p>
    @if($rows->isNotEmpty())
        <table style="border-collapse: collapse; width: 100%">
            <thead><tr>@foreach(['Identifier', 'Title', 'Date', 'Type', 'Method', 'Amount'] as $h)<th scope="col" style="text-align: left; padding-right: 1rem">{{ __($h) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($rows as $v)
                <tr>
                    <td style="padding-right: 1rem"><a href="{{ route('accessions.show', $v->accession) }}#valuation">{{ $v->accession->number() }}</a></td>
                    <td style="padding-right: 1rem">{{ $v->accession->described('accession-title', [app()->getLocale(), 'en']) }}</td>
                    <td style="padding-right: 1rem">{{ $v->valued_on->format('Y-m-d') }}</td>
                    <td style="padding-right: 1rem">{{ $v->type->label($lang) }}</td>
                    <td style="padding-right: 1rem">{{ $v->method?->label($lang) }}</td>
                    <td style="padding-right: 1rem; text-align: right">{{ $v->currency }} {{ number_format((float) $v->monetary_value, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p>{{ __('No accession has been valued yet.') }}</p>
    @endif
@endsection
