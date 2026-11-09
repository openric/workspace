{{-- OpenRiC Workspace - the institution's AI register and compliance (roadmap phase 17f), after Heratio's ahg-ai-compliance pages: each AI system with its risk class, oversight and how reviewers dealt with its output, halted or not; open incidents; the models seen; reviewers' attestations. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('AI register and compliance'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('ai.index') }}">{{ __('AI') }}</a></p>
    <h1>{{ __('AI register and compliance') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('Every AI system the Workspace may use for the institution, with its purpose, risk class and the human oversight it is under, after the EU AI Act (articles 9, 12 and 14, Annex IV). For a South African institution the same register serves the accountability principle of POPIA. Each call a model answers is kept in the chained, signed log of inferences.') }}
        <a href="{{ route('ai.inferences') }}">{{ __('The log of inferences') }}</a></p>

    <h2>{{ __('AI systems') }}</h2>
    <table><thead><tr><th scope="col">{{ __('System') }}</th><th scope="col">{{ __('Risk class') }}</th><th scope="col">{{ __('Review') }}</th><th scope="col">{{ __('Last 30 days') }}</th><th scope="col">{{ __('Halt') }}</th></tr></thead>
        <tbody>@foreach($systems as $s)
            @php($o = $oversight[$s->service->notation] ?? ['proposed' => 0, 'accepted' => 0, 'rejected' => 0])
            <tr><td><a href="{{ route('ai.systems.show', $s) }}">{{ $s->name }}</a><br><small>{{ $s->lifecycle->label($lang) }}</small></td>
                <td>{{ $s->riskClass->label($lang) }}</td>
                <td>{{ $s->requires_human_review ? __('Every output reviewed') : __('Answers flagged by readers reviewed') }}</td>
                <td>{{ __(':p waiting, :a accepted, :r rejected', ['p' => $o['proposed'], 'a' => $o['accepted'], 'r' => $o['rejected']]) }}</td>
                <td>@if($s->halted_at)
                        <strong>{{ __('Halted') }}</strong> <small>{{ $s->halted_reason }}</small>
                        <form method="post" action="{{ route('ai.systems.halt', $s) }}">@csrf<button type="submit">{{ __('Resume') }}</button></form>
                    @else
                        <form method="post" action="{{ route('ai.systems.halt', $s) }}">@csrf<label for="halt-{{ $s->id }}" class="visually-hidden">{{ __('Why halt :name', ['name' => $s->name]) }}</label>
                            <input id="halt-{{ $s->id }}" name="reason" required maxlength="255" placeholder="{{ __('Why') }}" size="12"><button type="submit">{{ __('Halt') }}</button></form>
                    @endif</td></tr>
        @endforeach</tbody></table>

    <h2>{{ __('Open incidents') }}</h2>
    @forelse($incidents as $i)
        <p><a href="{{ route('ai.systems.show', $i->system) }}">{{ $i->system->name }}</a>: {{ $i->description }} <small>({{ $i->severity->label($lang) }}, {{ $i->created_at->toDateString() }}{{ $i->raised_by === 'monitor' ? ', '.__('raised by the monitor') : '' }})</small></p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse

    <h2>{{ __('Models') }}</h2>
    <p class="meta">{{ __('Every model and version that has answered, from the log of inferences.') }}</p>
    <table><thead><tr><th scope="col">{{ __('Model') }}</th><th scope="col">{{ __('Service') }}</th><th scope="col">{{ __('Calls') }}</th><th scope="col">{{ __('Seen') }}</th><th scope="col">{{ __('What is known of it') }}</th></tr></thead>
        <tbody>@forelse($models as $m)
            <tr><td>{{ $m->model }}<br><small>{{ $m->model_version }}</small>@if($m->retired_at)<br><small>{{ __('Retired') }}</small>@endif</td><td>{{ $m->service->label($lang) }}</td><td>{{ number_format($m->calls) }}</td>
                <td>{{ $m->first_seen_at->toDateString() }} - {{ $m->last_seen_at->toDateString() }}</td>
                <td><details><summary>{{ $m->known_limits ? \Illuminate\Support\Str::limit($m->known_limits, 60) : __('Add') }}</summary>
                    <form method="post" action="{{ route('ai.models.update', $m) }}">@csrf
                        <label for="tr-{{ $m->id }}">{{ __('Training data') }}</label><textarea id="tr-{{ $m->id }}" name="training_data_summary" rows="2">{{ $m->training_data_summary }}</textarea>
                        <label for="kl-{{ $m->id }}">{{ __('Known limits') }}</label><textarea id="kl-{{ $m->id }}" name="known_limits" rows="2">{{ $m->known_limits }}</textarea>
                        <label for="ip-{{ $m->id }}">{{ __('Intended purpose') }}</label><textarea id="ip-{{ $m->id }}" name="intended_purpose" rows="2">{{ $m->intended_purpose }}</textarea>
                        <p><label><input type="checkbox" name="retired" value="1" @checked($m->retired_at)> {{ __('Retired') }}</label></p>
                        <button type="submit">{{ __('Save') }}</button></form></details></td></tr>
        @empty<tr><td colspan="5">{{ __('No model has answered yet.') }}</td></tr>@endforelse</tbody></table>

    <h2>{{ __('Reviewers\' attestations') }}</h2>
    <p>{{ __('Who has attested, in the last year, that they know how to review AI output.') }} <a href="{{ route('ai.attestation') }}">{{ __('The attestation') }}</a></p>
    <ul>@forelse($attestations as $a)<li>{{ $a->user?->name }}: {{ $a->attested_at->toDateString() }}</li>@empty<li>{{ __('None yet.') }}</li>@endforelse</ul>
@endsection
