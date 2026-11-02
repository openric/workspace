{{-- OpenRiC Workspace - records management compliance assessments (Heratio's compliance pages; roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Compliance'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Compliance assessments') }}</h1>
    @include('records-management._nav')
    <table><thead><tr><th scope="col">{{ __('Reference') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Standard') }}</th><th scope="col">{{ __('Score') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($assessments as $a)<tr><td><a href="{{ route('records-management.assessment', $a) }}">{{ $a->reference }}</a></td><td>{{ $a->title }}</td><td>{{ $a->framework->label($lang) }}</td>
            <td>{{ \App\Retention\Compliance::percent($a) ?? '-' }}%</td><td>{{ $a->status->label($lang) }}</td></tr>@empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
    <h2>{{ __('Assess') }}</h2>
    <form method="post" action="{{ route('records-management.compliance.store') }}">@csrf
        <label for="c-fw">{{ __('Standard') }}</label> @include('projects._select', ['id' => 'c-fw', 'name' => 'framework', 'choices' => $frameworks, 'current' => null, 'default' => 'iso-15489'])
        <label for="c-ref">{{ __('Reference') }}</label> <input id="c-ref" name="reference" required size="15"> <label for="c-title">{{ __('Title') }}</label> <input id="c-title" name="title" required size="40"><br>
        <label for="c-from">{{ __('From') }}</label> <input id="c-from" name="period_start" type="date"> <label for="c-to">{{ __('To') }}</label> <input id="c-to" name="period_end" type="date"><br>
        <label for="c-scope">{{ __('Scope') }}</label><br><textarea id="c-scope" name="scope" rows="2" cols="80"></textarea><br><button type="submit">{{ __('Assess') }}</button></form>
@endsection
