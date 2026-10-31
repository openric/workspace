{{-- OpenRiC Workspace - one curated IIIF collection: its items, in order, and its fields (roadmap phase 14a, ADR 0014), after Heratio's iiif-collection view, edit and add-items. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $collection->label)
@section('content')
    <p><a href="{{ route('iiif-collections.index') }}">{{ __('IIIF collections') }}</a></p>
    <h1>{{ $collection->label }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @if($collection->is_public)<p class="meta">{{ __('Published as') }} <a href="{{ route('iiif.curated', $collection) }}">{{ route('iiif.curated', $collection) }}</a></p>
    @else<p class="meta">{{ __('Not public.') }}</p>@endif
    <h2>{{ __('Items') }}</h2>
    @if($items->isEmpty())<p>{{ __('Nothing in it yet.') }}</p>@else
    <ol>@foreach($items as $item)
        <li>@if($item->record)<a href="{{ route('records.show', $item->record) }}">{{ $item->label ?? \App\Relations\EntityKinds::label($item->record) }}</a> ({{ __('record') }})
            @elseif($item->memberCollection)<a href="{{ route('iiif-collections.show', $item->memberCollection) }}">{{ $item->label ?? $item->memberCollection->label }}</a> ({{ __('collection') }})
            @else<a href="{{ $item->uri }}">{{ $item->label ?? $item->uri }}</a> ({{ $item->resourceType->label(\App\Support\Vocab::languages()) }})@endif
            <form method="post" action="{{ route('iiif-collections.items.move', [$collection, $item, 'up']) }}" style="display: inline">@csrf<button type="submit" @disabled($loop->first) aria-label="{{ __('Move up') }}">↑</button></form>
            <form method="post" action="{{ route('iiif-collections.items.move', [$collection, $item, 'down']) }}" style="display: inline">@csrf<button type="submit" @disabled($loop->last) aria-label="{{ __('Move down') }}">↓</button></form>
            <form method="post" action="{{ route('iiif-collections.items.remove', [$collection, $item]) }}" style="display: inline">@csrf<button type="submit">{{ __('Remove') }}</button></form></li>
    @endforeach</ol>@endif
    <h2>{{ __('Add an item') }}</h2>
    <form method="post" action="{{ route('iiif-collections.items.add', $collection) }}">@csrf
        <p><label for="target">{{ __('A record\'s persistent IRI, or another institution\'s IIIF manifest or collection URI') }}</label><br><input id="target" name="target" type="url" size="70" value="{{ old('target') }}">
            <label for="type">{{ __('An outside URI is a') }}</label> <select id="type" name="type">@foreach($types as $t)<option value="{{ $t->notation }}">{{ $t->label(\App\Support\Vocab::languages()) }}</option>@endforeach</select></p>
        @if($others->isNotEmpty())<p><label for="member">{{ __('Or another curated collection') }}</label> <select id="member" name="member"><option value="">-</option>@foreach($others as $o)<option value="{{ $o->id }}">{{ $o->label }}</option>@endforeach</select></p>@endif
        <p><label for="item-label">{{ __('Label in the collection (optional)') }}</label> <input id="item-label" name="label" maxlength="500" size="40"></p>
        <button type="submit">{{ __('Add') }}</button>
    </form>
    <h2>{{ __('Edit') }}</h2>
    <form method="post" action="{{ route('iiif-collections.update', $collection) }}">@csrf @include('iiif._fields')<button type="submit">{{ __('Save') }}</button></form>
    <form method="post" action="{{ route('iiif-collections.destroy', $collection) }}" style="margin-top: 1rem">@csrf<button type="submit" style="background: var(--error)">{{ __('Delete this collection') }}</button></form>
@endsection
