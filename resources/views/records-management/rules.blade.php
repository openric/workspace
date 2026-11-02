{{-- OpenRiC Workspace - classification rules and what they placed (Heratio's classification pages; roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Classification rules'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Classification rules') }}</h1>
    @include('records-management._nav')
    <p>{{ __('The highest priority first; the first rule that matches places the record, or the captured e-mail, in its node of the file plan, with its disposal class when it names one.') }}</p>
    @forelse($rules as $r)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $r->name }}</strong> · {{ $r->ruleType->label($lang) }}: <code>{{ $r->match_pattern }}</code> → {{ $r->node->code }} {{ $r->node->title }}@if($r->disposalClass) · {{ $r->disposalClass->reference }}@endif
                · {{ $r->applyOn->label($lang) }} · {{ __('priority :n', ['n' => $r->priority]) }}@unless($r->is_active) · {{ __('not in use') }}@endunless</p>
            <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('records-management.rules.save', $r) }}">@csrf @include('records-management._rule-fields', ['r' => $r, 'x' => 'r'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('records-management.rules.save', $r) }}">@csrf<input type="hidden" name="remove" value="1"><button type="submit">{{ __('Remove') }}</button></form></details>
        </section>
    @empty
        <p>{{ __('No rules yet.') }}</p>
    @endforelse
    <details><summary>{{ __('Add a rule') }}</summary><form method="post" action="{{ route('records-management.rules.save') }}">@csrf @include('records-management._rule-fields', ['r' => null, 'x' => 'rn'])<button type="submit">{{ __('Add') }}</button></form></details>
    <form method="post" action="{{ route('records-management.rules.classify') }}">@csrf<button type="submit">{{ __('Place the records that have no place yet') }}</button></form>
    @if($log->isNotEmpty())<h2>{{ __('Placed by the rules') }}</h2><ul class="meta">@foreach($log as $l)<li>{{ $l->classified_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }} · {{ $l->rule->name }} → {{ $l->node->code }} · {{ $l->match_detail }}</li>@endforeach</ul>@endif
@endsection
