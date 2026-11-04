{{-- OpenRiC Workspace - print templates for call slips, paging slips, receipts, badges and labels (Heratio's research_print_template; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Print templates'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Print templates') }}</h1>
    @include('research._head')
    <p>{{ __('A template is plain text with placeholders in braces. A call slip or paging slip may hold:') }} @foreach($placeholders as $p)<code>{{ '{'.$p.'}' }}</code> @endforeach</p>
    @forelse($templates as $t)
        <p><strong>{{ $t->name }}</strong> ({{ $t->code }}) · {{ $t->templateType->label($lang) }} · {{ $t->pageSize?->label($lang) }} {{ $t->pageOrientation?->label($lang) }}@if($t->is_default) · {{ __('default') }}@endif @unless($t->is_active) · {{ __('not in use') }}@endunless</p>
        <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('research.print-templates.save', $t) }}">@csrf @include('research._print-fields', ['t' => $t, 'x' => 'p'.$loop->index])<button type="submit">{{ __('Save') }}</button></form></details>
    @empty
        <p>{{ __('None: call slips are laid out as the Workspace lays them out.') }}</p>
    @endforelse
    <details><summary>{{ __('Add a template') }}</summary><form method="post" action="{{ route('research.print-templates.save') }}">@csrf @include('research._print-fields', ['t' => null, 'x' => 'pn'])<button type="submit">{{ __('Add') }}</button></form></details>
@endsection
