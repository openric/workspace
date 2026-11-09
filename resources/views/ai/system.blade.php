{{-- OpenRiC Workspace - one AI system in the register (roadmap phase 17f), after Heratio's ahg-ai-compliance: its details and oversight, its risks (article 9) and incidents, and its technical documentation (Annex IV). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $system->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($pick = fn (string $name, $choices, ?string $current) => $choices->map(fn ($c) => '<option value="'.e($c->notation).'"'.($c->notation === $current ? ' selected' : '').'>'.e($c->label($lang)).'</option>')->join(''))
    <p><a href="{{ route('ai.compliance') }}">{{ __('AI register and compliance') }}</a></p>
    <h1>{{ $system->name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if($system->halted_at)<p role="status"><strong>{{ __('Halted since :d', ['d' => $system->halted_at->toDateString()]) }}:</strong> {{ $system->halted_reason }}</p>@endif

    <h2>{{ __('The system') }}</h2>
    <form method="post" action="{{ route('ai.systems.update', $system) }}">@csrf
        <label for="s-name">{{ __('Name') }}</label><input id="s-name" name="name" required maxlength="255" value="{{ old('name', $system->name) }}">
        <label for="s-purpose">{{ __('Intended purpose') }}</label><textarea id="s-purpose" name="purpose" rows="2">{{ old('purpose', $system->purpose) }}</textarea>
        <label for="s-desc">{{ __('Description') }}</label><textarea id="s-desc" name="description" rows="2">{{ old('description', $system->description) }}</textarea>
        <label for="s-provider">{{ __('Provider') }}</label><input id="s-provider" name="provider" maxlength="255" value="{{ old('provider', $system->provider) }}">
        <label for="s-role">{{ __('The institution\'s role') }}</label><select id="s-role" name="role">{!! $pick('role', $roles, $system->role?->notation) !!}</select>
        <label for="s-class">{{ __('Risk class') }}</label><select id="s-class" name="risk_class">{!! $pick('risk_class', $classes, $system->riskClass?->notation) !!}</select>
        <label for="s-life">{{ __('Lifecycle') }}</label><select id="s-life" name="lifecycle">{!! $pick('lifecycle', $lifecycles, $system->lifecycle?->notation) !!}</select>
        <label for="s-context">{{ __('Where and how it is used') }}</label><textarea id="s-context" name="deployment_context" rows="2">{{ old('deployment_context', $system->deployment_context) }}</textarea>
        <label for="s-oversight">{{ __('Human oversight') }}</label><textarea id="s-oversight" name="human_oversight" rows="3">{{ old('human_oversight', $system->human_oversight) }}</textarea>
        <p><label><input type="checkbox" name="requires_human_review" value="1" @checked($system->requires_human_review)> {{ __('A person reviews every output') }}</label>
            <label><input type="checkbox" name="dual_review_required" value="1" @checked($system->dual_review_required)> {{ __('Two people review each output') }}</label></p>
        <label for="s-bias">{{ __('Reminder shown to reviewers') }}</label><input id="s-bias" name="automation_bias_text" maxlength="512" value="{{ old('automation_bias_text', $system->automation_bias_text) }}">
        <label for="s-owner">{{ __('Accountable person or unit') }}</label><input id="s-owner" name="owner" maxlength="255" value="{{ old('owner', $system->owner) }}">
        <label for="s-last">{{ __('Last reviewed') }}</label><input id="s-last" name="last_review_date" type="date" value="{{ $system->last_review_date?->toDateString() }}">
        <label for="s-next">{{ __('Next review') }}</label><input id="s-next" name="next_review_date" type="date" value="{{ $system->next_review_date?->toDateString() }}">
        <button type="submit">{{ __('Save') }}</button>
    </form>

    <h2>{{ __('Risks') }}</h2>
    @foreach($system->risks as $r)
        <details><summary>{{ $r->description }} <small>({{ $r->severity->label($lang) }}, {{ $r->status->label($lang) }})</small></summary>
            @include('ai._risk-form', ['action' => route('ai.risks.update', $r), 'risk' => $r, 'prefix' => 'r-'.$r->id])</details>
    @endforeach
    <details><summary>{{ __('Add a risk') }}</summary>@include('ai._risk-form', ['action' => route('ai.risks.store', $system), 'risk' => null, 'prefix' => 'new-risk'])</details>

    <h2>{{ __('Incidents') }}</h2>
    @forelse($system->incidents->sortByDesc('created_at') as $i)
        <p>{{ $i->created_at->toDateString() }}: {{ $i->description }} <small>({{ $i->severity->label($lang) }}{{ $i->raised_by === 'monitor' ? ', '.__('raised by the monitor') : '' }})</small>
            @if($i->resolved_at)<br><small>{{ __('Resolved :d: :how', ['d' => $i->resolved_at->toDateString(), 'how' => $i->resolution]) }}</small>
            @else
                <form method="post" action="{{ route('ai.incidents.resolve', $i) }}">@csrf<label for="res-{{ $i->id }}">{{ __('How it was resolved') }}</label>
                    <input id="res-{{ $i->id }}" name="resolution" required maxlength="5000"><button type="submit">{{ __('Resolve') }}</button></form>
            @endif</p>
    @empty
        <p>{{ __('None.') }}</p>
    @endforelse
    <details><summary>{{ __('Record an incident') }}</summary>
        <form method="post" action="{{ route('ai.incidents.store', $system) }}">@csrf
            <label for="inc-desc">{{ __('What happened') }}</label><textarea id="inc-desc" name="description" rows="3" required></textarea>
            <label for="inc-sev">{{ __('Severity') }}</label><select id="inc-sev" name="severity">{!! $pick('severity', $levels, 'medium') !!}</select>
            <label for="inc-risk">{{ __('The risk it bears on (optional)') }}</label><select id="inc-risk" name="risk_id"><option value="">{{ __('None') }}</option>@foreach($system->risks as $r)<option value="{{ $r->id }}">{{ \Illuminate\Support\Str::limit($r->description, 80) }}</option>@endforeach</select>
            <button type="submit">{{ __('Record') }}</button></form></details>

    <h2>{{ __('Technical documentation (Annex IV)') }}</h2>
    <p><a href="{{ route('ai.systems.annex', $system) }}">{{ __('Download as Markdown') }}</a></p>
    @foreach($annex as [$title, $text])
        <h3>{{ $title }}</h3>
        <p style="white-space: pre-line">{{ $text }}</p>
    @endforeach
@endsection
