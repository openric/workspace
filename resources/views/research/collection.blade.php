{{-- OpenRiC Workspace - a collection (Heratio's viewCollection; roadmap phase 19d): its items with notes, tags, reference codes and order; adding by IRI or link; the finding aid; sharing. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $collection->name)
@section('content')
    <p><a href="{{ route('research.collections.index') }}">{{ __('Collections') }}</a></p>
    <h1>{{ $collection->name }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}@if(session('share_link')) <code>{{ session('share_link') }}</code>@endif</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($collection->description)<p>{{ $collection->description }}</p>@endif
    <p><a href="{{ route('research.collections.finding-aid', $collection) }}">{{ __('Finding aid') }}</a> · @include('research._project-select', ['thing' => $collection, 'kind' => 'collection'])</p>
    @include('research._comments', ['subject' => $collection, 'kind' => 'collection'])
    @forelse($items as $i)
        <section style="border-top: 1px solid var(--line); padding: .3rem 0">
            <p>@if($i['url'])<a href="{{ $i['url'] }}">{{ $i['title'] }}</a>@else{{ $i['title'] }}@endif @if($i['item']->reference_code)<span class="meta">{{ $i['item']->reference_code }}</span>@endif
                @if($i['item']->tags)<br><span class="meta">{{ __('Tags') }}: {{ $i['item']->tags }}</span>@endif @if($i['item']->notes)<br>{{ $i['item']->notes }}@endif</p>
            <details><summary>{{ __('Change') }}</summary>
                <form method="post" action="{{ route('research.collections.item', [$i['item'], 'update']) }}">@csrf
                    <label for="ci-notes-{{ $loop->index }}">{{ __('Notes') }}</label><br><textarea id="ci-notes-{{ $loop->index }}" name="notes" rows="3" cols="70">{{ $i['item']->notes }}</textarea><br>
                    <label for="ci-tags-{{ $loop->index }}">{{ __('Tags') }}</label> <input id="ci-tags-{{ $loop->index }}" name="tags" value="{{ $i['item']->tags }}" size="30">
                    <label for="ci-ref-{{ $loop->index }}">{{ __('Reference code') }}</label> <input id="ci-ref-{{ $loop->index }}" name="reference_code" value="{{ $i['item']->reference_code }}" size="15">
                    <label for="ci-order-{{ $loop->index }}">{{ __('Order') }}</label> <input id="ci-order-{{ $loop->index }}" name="sort_order" type="number" min="0" value="{{ $i['item']->sort_order }}" style="width: 5rem">
                    <button type="submit">{{ __('Save') }}</button></form>
                <form method="post" action="{{ route('research.collections.item', [$i['item'], 'remove']) }}">@csrf<button type="submit">{{ __('Remove') }}</button></form>
            </details>
        </section>
    @empty<p>{{ __('No item yet.') }}</p>@endforelse
    <h2>{{ __('Add') }}</h2>
    <form method="post" action="{{ route('research.collections.step', [$collection, 'import']) }}">@csrf<label for="rc-iris">{{ __('Records of the catalogue, by their persistent IRIs, one a line') }}</label><br><textarea id="rc-iris" name="iris" rows="3" cols="70" required></textarea><br><button type="submit">{{ __('Add') }}</button></form>
    <details><summary>{{ __('A link') }}</summary><form method="post" action="{{ route('research.collections.step', [$collection, 'link']) }}">@csrf
        <label for="rl-url">{{ __('Web address') }}</label> <input id="rl-url" name="url" type="url" required size="40"> <label for="rl-title">{{ __('Title') }}</label> <input id="rl-title" name="title" required>
        <label for="rl-notes">{{ __('Notes') }}</label> <input id="rl-notes" name="notes" size="30"> <button type="submit">{{ __('Add') }}</button></form></details>
    <h2>{{ __('Settings') }}</h2>
    <form method="post" action="{{ route('research.collections.update', $collection) }}">@csrf
        <label for="cs-name">{{ __('Name') }}</label> <input id="cs-name" name="name" required value="{{ $collection->name }}">
        <label for="cs-desc">{{ __('Description') }}</label> <input id="cs-desc" name="description" size="40" value="{{ $collection->description }}"> <button type="submit">{{ __('Save') }}</button></form>
    <form method="post" action="{{ route('research.collections.step', [$collection, 'share']) }}">@csrf<input type="hidden" name="share" value="{{ $collection->is_public ? 0 : 1 }}"><button type="submit">{{ $collection->is_public ? __('Stop sharing') : __('Share it by a link') }}</button></form>
    <form method="post" action="{{ route('research.collections.step', [$collection, 'delete']) }}">@csrf<button type="submit">{{ __('Delete the collection') }}</button></form>
@endsection
