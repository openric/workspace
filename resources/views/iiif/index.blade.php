{{-- OpenRiC Workspace - curated IIIF collections (roadmap phase 14a, ADR 0014), after Heratio's iiif-collection index. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('IIIF collections'))
@section('content')
    <h1>{{ __('IIIF collections') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('A curated collection gathers records, other collections and other institutions\' IIIF resources for a viewer such as Mirador. Each published record with an image, sound or video already has its own manifest, and each record with parts its own collection.') }}
        <a href="{{ route('iiif.top') }}">{{ __('The institution\'s IIIF collection') }}</a></p>
    @if($collections->isEmpty())<p>{{ __('No collections yet.') }}</p>@else
    <table>
        <thead><tr><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Items') }}</th><th scope="col">{{ __('Inside') }}</th><th scope="col">{{ __('Public') }}</th></tr></thead>
        <tbody>@foreach($collections as $c)
            <tr><td><a href="{{ route('iiif-collections.show', $c) }}">{{ $c->label }}</a></td><td>{{ $c->items_count }}</td><td>{{ $c->parent?->label }}</td><td>{{ $c->is_public ? __('Yes') : __('No') }}</td></tr>
        @endforeach</tbody>
    </table>@endif
    <details @if($errors->has('collection')) open @endif><summary>{{ __('Add a collection') }}</summary>
        <form method="post" action="{{ route('iiif-collections.store') }}">@csrf @include('iiif._fields', ['collection' => null, 'others' => $collections])<button type="submit">{{ __('Add') }}</button></form>
    </details>
@endsection
