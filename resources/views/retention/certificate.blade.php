{{-- OpenRiC Workspace - a certificate of destruction, to print (roadmap phase 13b, ADR 0015), after Heratio's DestructionCertificateService certificate. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Certificate of destruction :number', ['number' => $certificate->number]))
@section('content')
    <p>@if($disposal)<a href="{{ route('disposals.show', $disposal) }}">{{ __('The disposal') }}</a>@endif</p>
    <h1>{{ __('Certificate of destruction') }}</h1>
    <p><strong>{{ $institution->name }}</strong> · {{ __('Number') }}: <strong>{{ $certificate->number }}</strong> · {{ __('Destroyed on :date', ['date' => $certificate->destroyed_at->format('Y-m-d H:i')]) }}</p>
    <table>
        <tbody>
            <tr><th scope="row">{{ __('Record') }}</th><td>{{ $certificate->record_title }}</td></tr>
            <tr><th scope="row">{{ __('Reference code') }}</th><td>{{ $certificate->reference_code ?? '-' }}</td></tr>
            <tr><th scope="row">{{ __('Persistent IRI') }}</th><td><code>{{ $certificate->record_iri }}</code></td></tr>
            <tr><th scope="row">{{ __('Authority') }}</th><td>{{ $certificate->authority ?? '-' }}</td></tr>
            <tr><th scope="row">{{ __('Disposal class') }}</th><td>{{ $certificate->disposal_class ?? '-' }}</td></tr>
            <tr><th scope="row">{{ __('Method') }}</th><td>{{ $certificate->method }}</td></tr>
            <tr><th scope="row">{{ __('Approved by') }}</th><td>{{ $certificate->approved_by }}</td></tr>
            <tr><th scope="row">{{ __('Carried out by') }}</th><td>{{ $certificate->executed_by }}</td></tr>
            <tr><th scope="row">{{ __('Witness') }}</th><td>{{ $certificate->witness ?? '-' }}</td></tr>
            <tr><th scope="row">{{ __('Content checksum (SHA-256)') }}</th><td><code>{{ $certificate->content_hash ?? '-' }}</code></td></tr>
        </tbody>
    </table>
    <h2>{{ __('Files destroyed') }}</h2>
    @if($certificate->files === [])<p>{{ __('None: the record had no files.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('File') }}</th><th scope="col">{{ __('Use') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">SHA-256</th></tr></thead>
        <tbody>@foreach($certificate->files as $f)<tr><td>{{ $f['name'] }}@if($f['shared'] ?? false) ({{ __('kept: another record uses the same file') }})@endif</td><td>{{ $f['use'] }}</td>
            <td>{{ \Illuminate\Support\Number::fileSize($f['size_bytes'] ?? 0, 1) }}</td><td><code>{{ $f['sha256'] }}</code></td></tr>@endforeach</tbody></table>@endif
@endsection
