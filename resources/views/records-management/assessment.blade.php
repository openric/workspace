{{-- OpenRiC Workspace - a compliance assessment: its checks, score and recommendations (roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $a->reference.' '.$a->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('records-management.compliance') }}">{{ __('Compliance assessments') }}</a></p>
    <h1>{{ $a->reference }} {{ $a->title }}</h1>
    @include('records-management._nav')
    <p>{{ $a->framework->label($lang) }} · {{ $a->status->label($lang) }} · {{ __('Score') }}: <strong>{{ \App\Retention\Compliance::percent($a) ?? '-' }}%</strong> ({{ $a->score_total }} / {{ $a->score_max }})
        · {{ __('assessed :date by :name', ['date' => $a->assessed_at->timezone(config('workspace.timezone'))->format('j M Y H:i'), 'name' => $a->assessedBy?->name ?? '-']) }}
        @if($a->period_start || $a->period_end) · {{ $a->period_start?->format('Y-m-d') ?? '…' }} - {{ $a->period_end?->format('Y-m-d') ?? '…' }}@endif
        @if($a->signed_off_at) · {{ __('signed off by :name on :date', ['name' => $a->signed_off_by, 'date' => $a->signed_off_at->format('j M Y')]) }}@endif</p>
    @if($a->scope)<p style="white-space: pre-wrap">{{ $a->scope }}</p>@endif
    @php($states = ['pass' => __('Pass'), 'warn' => __('Partly'), 'fail' => __('Fail')])
    <table><thead><tr><th scope="col">{{ __('Check') }}</th><th scope="col">{{ __('Result') }}</th><th scope="col">{{ __('Finding') }}</th><th scope="col">{{ __('Weight') }}</th></tr></thead>
        <tbody>@foreach($a->findings ?? [] as $f)<tr><th scope="row">{{ $f['ref'] }} {{ $f['label'] }}</th><td>@if($f['status'] === 'pass'){{ $states['pass'] }}@else<strong>{{ $states[$f['status']] ?? $f['status'] }}</strong>@endif</td><td>{{ $f['finding'] }}</td><td>{{ $f['weight'] }}</td></tr>@endforeach</tbody></table>

    @if($a->recommendations)<h2>{{ __('Recommendations') }}</h2><ul>@foreach($a->recommendations as $r)<li><strong>{{ $r['ref'] }}</strong>: {{ $r['recommendation'] }}</li>@endforeach</ul>@endif
    @if($a->status->notation !== 'finalised')
        <form method="post" action="{{ route('records-management.assessment.step', [$a, 'run']) }}" style="display: inline">@csrf<button type="submit">{{ __('Assess again') }}</button></form>
        <form method="post" action="{{ route('records-management.assessment.step', [$a, 'sign-off']) }}" style="display: inline">@csrf<label for="so">{{ __('Signed off by') }}</label> <input id="so" name="signed_off_by" required size="30"> <button type="submit">{{ __('Sign off') }}</button></form>
    @endif
@endsection
