{{-- OpenRiC Workspace - a node of the file plan and the records placed in it (roadmap phase 13c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $node->code.' '.$node->title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('records-management.file-plan') }}">{{ __('File plan') }}</a>@foreach($trail as $t) / <a href="{{ route('records-management.node', $t) }}">{{ $t->code }}</a>@endforeach</p>
    <h1>{{ $node->code }} {{ $node->title }}</h1>
    @include('records-management._nav')
    <p>{{ $node->nodeType->label($lang) }} · {{ $node->status->label($lang) }}@if($node->activity) · {{ __('Records here document') }} <a href="{{ route('activities.show', $node->activity) }}">{{ \App\Relations\EntityKinds::label($node->activity) }}</a> <span class="meta">(rico:documents)</span>@endif
        @if($node->disposalClass) · {{ __('Disposal class') }} {{ $node->disposalClass->reference }} {{ $node->disposalClass->title }}@endif
        @if($node->retention_period) · {{ $node->retention_period }}@endif @if($node->disposalAction) · {{ $node->disposalAction->label($lang) }}@endif</p>
    @if($node->description)<p style="white-space: pre-wrap">{{ $node->description }}</p>@endif
    @if($children->isNotEmpty())<p>{{ __('Under it') }}: @foreach($children as $c)<a href="{{ route('records-management.node', $c) }}">{{ $c->code }} {{ $c->title }}</a>@if(! $loop->last), @endif @endforeach</p>@endif
    <h2>{{ __('Records') }}</h2>
    <ul>@forelse($placements as $p)<li><a href="{{ route('records.show', $p->record) }}">{{ \App\Relations\EntityKinds::label($p->record) }}</a> <span class="meta">{{ $p->placed_at->format('Y-m-d') }}@if($p->rule) · {{ __('by the rule :name', ['name' => $p->rule->name]) }}@elseif($p->placedBy) · {{ $p->placedBy->name }}@endif</span></li>@empty<li>{{ __('None.') }}</li>@endforelse</ul>
    {{ $placements->links() }}
    <details><summary>{{ __('Change') }}</summary><form method="post" action="{{ route('records-management.nodes.update', $node) }}">@csrf @include('records-management._node-fields', ['n' => $node, 'x' => 'ne'])
        <button type="submit">{{ __('Save') }}</button></form>
        <form method="post" action="{{ route('records-management.nodes.remove', $node) }}">@csrf<button type="submit">{{ __('Remove the node') }}</button></form></details>
@endsection
