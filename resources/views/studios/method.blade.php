{{-- OpenRiC Workspace - a project's method studio (Heratio's method studio; roadmap phase 19g): protocols from method templates. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Method studio').': '.$project->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @include('studios._nav')
    <h1>{{ __('Method studio') }}</h1>
    @forelse($protocols as $p)<p><a href="{{ route('research.studios.method', [$project, 'protocol' => $p->id]) }}">{{ $p->title }}</a> · {{ $p->template->name }} · {{ $p->status->label($lang) }} · {{ trans_choice(':count area written|:count areas written', count($p->fields), ['count' => count($p->fields)]) }}</p>
    @empty<p>{{ __('None.') }}</p>@endforelse
    @if($editing)
        <h2>{{ $editing->title }}</h2>
        <p class="meta">{{ $editing->template->name }}@if($editing->template->discipline) · {{ $editing->template->discipline }}@endif</p>
        <form method="post" action="{{ route('research.studios.method.save', [$project, $editing]) }}">@csrf
            <label for="mp-title">{{ __('Title') }}</label> <input id="mp-title" name="title" size="40" value="{{ $editing->title }}">
            <label for="mp-status">{{ __('Status') }}</label> <select id="mp-status" name="status">@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($editing->status->notation === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select>
            @foreach(\App\Research\Studios::AREAS as $area => $label)
                @php($g = $editing->template->guidance[$area] ?? [])
                <p><label for="mp-{{ $area }}"><strong>{{ $g['label'] ?? __($label) }}</strong></label>@if($g['prompt'] ?? null)<br><span class="meta">{{ $g['prompt'] }}</span>@endif<br>
                    <textarea id="mp-{{ $area }}" name="fields[{{ $area }}]" rows="3" cols="90" @readonly($level < 2) placeholder="{{ $g['placeholder'] ?? '' }}">{{ $editing->fields[$area] ?? '' }}</textarea></p>
            @endforeach
            @if($level >= 2)<button type="submit">{{ __('Save') }}</button>@endif</form>
    @endif
    @if($level >= 2)<h2>{{ __('A new protocol') }}</h2>
        @foreach($templates as $t)<p><strong>{{ $t->name }}</strong> <span class="meta">{{ $t->discipline }}</span><br>{{ $t->description }}</p>@endforeach
        <form method="post" action="{{ route('research.studios.method.save', $project) }}">@csrf<label for="mn-t">{{ __('Method') }}</label> <select id="mn-t" name="template">@foreach($templates as $t)<option value="{{ $t->code }}">{{ $t->name }}</option>@endforeach</select>
            <label for="mn-title">{{ __('Title') }}</label> <input id="mn-title" name="title" size="40"> <button type="submit">{{ __('Create') }}</button></form>@endif
@endsection
