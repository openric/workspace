{{-- OpenRiC Workspace - a research project's funding and grant applications (Heratio's project funding and grant engine pages; roadmap phase 19h). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Funding and grants').' - '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('projects._part-head')
    <h1>{{ __('Funding and grants') }}</h1>
    <p class="meta">{{ trans_choice(':count funding record|:count funding records', $summary['total'], ['count' => $summary['total']]) }} · {{ trans_choice(':count active now|:count active now', $summary['active_now'], ['count' => $summary['active_now']]) }}
        @foreach($summary['by_currency'] as $code => $t) · {{ __('Awarded') }}: {{ $code }} {{ number_format((float) $t['amount'], 2, '.', ' ') }}@endforeach</p>
    @forelse($project->funding as $f)
        <article>
            <h2>{{ $f->title }}</h2>
            <p>{{ $f->funder_name }} ({{ $f->funderType->label($lang) }}) · {{ $f->status->label($lang) }}@if(\App\Research\Funding::isActiveNow($f)) · <strong>{{ __('Active now') }}</strong>@endif
                @if($f->amount !== null) · {{ $f->currency->notation }} {{ number_format((float) $f->amount, 2, '.', ' ') }}@endif
                @if($f->award_reference) · {{ __('Award reference') }}: {{ $f->award_reference }}@endif
                @if($f->start_date || $f->end_date) · {{ $f->start_date?->format('j M Y') ?? '…' }} - {{ $f->end_date?->format('j M Y') ?? '…' }}@endif
                @if($f->dmp) · {{ __('Plan') }}: <a href="{{ route('research.projects.plans.show', [$project, $f->dmp]) }}">{{ $f->dmp->title }}</a>@endif</p>
            @if($f->notes)<p style="white-space: pre-wrap">{{ $f->notes }}</p>@endif
            @if($level >= 2)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.funding.save', [$project, $f]) }}">@csrf @include('projects._funding-fields', ['x' => 'f'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                @if($level >= 3 || $f->created_by_id === $user->id)<form method="post" action="{{ route('research.funding.part.remove', ['funding', $f->id]) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</details>@endif
        </article>
    @empty
        <p>{{ __('No funding is recorded.') }}</p>
    @endforelse
    @if($level >= 2)<details><summary>{{ __('Record funding') }}</summary><form method="post" action="{{ route('research.funding.save', $project) }}">@csrf @include('projects._funding-fields', ['f' => null, 'x' => 'fn'])<button type="submit">{{ __('Add') }}</button></form></details>@endif

    <h2>{{ __('Grant applications') }}</h2>
    @forelse($project->grantDrafts as $d)
        @php($c = \App\Research\Funding::completeness($d->sections))
        <p><a href="{{ route('research.grants.show', $d) }}">{{ $d->title }}</a> · {{ $d->template->label($lang) }} · {{ $d->status->label($lang) }} · {{ __(':filled of :total sections written', $c) }}
            @if($level >= 3 || $d->created_by_id === $user->id)<form method="post" action="{{ route('research.funding.part.remove', ['grants', $d->id]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form>@endif</p>
    @empty
        <p>{{ __('No grant application is being written.') }}</p>
    @endforelse
    @if($level >= 2)
        <form method="post" action="{{ route('research.grants.store', $project) }}">@csrf
            <label for="g-template">{{ __('Start an application from the template') }}</label> <select id="g-template" name="template">@foreach($templates as $c)<option value="{{ $c->notation }}">{{ $c->label($lang) }}</option>@endforeach</select>
            <label for="g-title">{{ __('Title') }}</label> <input id="g-title" name="title" size="40"> <button type="submit">{{ __('Start') }}</button></form>
    @endif
    <p><a href="{{ route('research.calls') }}">{{ __('Funding calls you watch') }}</a></p>
@endsection
