{{-- OpenRiC Workspace - a project's decision log (Heratio's decision log; roadmap phase 19i). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Decision log').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('reasoning._nav')
    <h1>{{ __('Decision log') }}</h1>
    <p>@foreach($types as $t)@if($counts[$t->notation] ?? 0)<a href="{{ route('research.reasoning.decisions', [$project, 'type' => $t->notation]) }}">{{ $t->label($lang) }}</a> {{ $counts[$t->notation] }} · @endif @endforeach <a href="{{ route('research.reasoning.decisions', $project) }}">{{ __('All') }}</a></p>
    @forelse($decisions as $d)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p><strong>{{ $d->summary }}</strong> <span class="meta">{{ $d->decisionType->label($lang) }} · {{ $d->decided_by }} · {{ $d->decided_at?->timezone(config('workspace.timezone'))->format('j M Y') }}@if($d->related_ref) · {{ $d->related_ref }}@endif</span></p>
            @if($d->reason)<p style="white-space: pre-wrap">{{ $d->reason }}</p>@endif
            @if($level >= 2)<details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.reasoning.decisions.save', [$project, $d]) }}">@csrf @include('reasoning._decision-fields', ['d' => $d, 'x' => 'dd'.$loop->index])<button type="submit">{{ __('Save') }}</button></form>
                @if($level >= 3 || $d->created_by_id === auth()->id())<form method="post" action="{{ route('research.reasoning.decisions.delete', $d) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form>@endif</details>@endif
        </section>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($level >= 2)<h2>{{ __('Record a decision') }}</h2><form method="post" action="{{ route('research.reasoning.decisions.save', $project) }}">@csrf @include('reasoning._decision-fields', ['d' => null, 'x' => 'dn'])<button type="submit">{{ __('Add') }}</button></form>@endif
@endsection
