{{-- OpenRiC Workspace - review, publish or withdraw an entity's description. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@php($name = \App\Relations\EntityKinds::label($entity))
@section('title', __('Publication of :name', ['name' => $name]))
@section('content')
    <p><a href="{{ \App\Relations\EntityKinds::route($entity) }}">{{ __('Back to :name', ['name' => $name]) }}</a></p>
    <h1>{{ __('Publication of :name', ['name' => $name]) }}</h1>
    <p>{{ __('Each value and name has its own state. Only published ones reach public discovery, the API and exports. A value someone changes goes back to draft, so it is published again only when someone publishes it.') }}</p>
    <table style="border-collapse: collapse">
        <tr><th style="text-align: left; padding: .25rem .75rem .25rem 0">{{ __('Language') }}</th>@foreach($states as $state)<th style="text-align: left; padding: .25rem .75rem">{{ $state->label(\App\Support\Vocab::languages()) }}</th>@endforeach</tr>
        @forelse($summary as $language => $counts)
            <tr><td style="padding: .25rem .75rem .25rem 0">{{ $language }}</td>@foreach($states as $notation => $state)<td style="padding: .25rem .75rem">{{ $counts[$notation] ?? 0 }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="5">{{ __('Nothing described yet.') }}</td></tr>
        @endforelse
    </table>
    <form method="post" action="{{ route('publication.update', [$kind, $entity->getKey()]) }}">
        @csrf
        <fieldset style="border: 0; padding: 0"><legend><strong>{{ __('What to do') }}</strong></legend>
            @if($mayReview)<label><input type="radio" name="action" value="review"> {{ __('Mark drafts as reviewed') }}</label>@endif
            @if($mayPublish)
                <label><input type="radio" name="action" value="publish" checked> {{ __('Publish drafts, reviewed and withdrawn values') }}</label>
                <label><input type="radio" name="action" value="withdraw"> {{ __('Withdraw what is published') }}</label>
            @endif
        </fieldset>
        <label for="language">{{ __('In one language only (BCP 47), or leave empty for every language') }}</label>
        <input id="language" name="language" maxlength="35" style="max-width: 8rem">
        @if($inside > 0)
            <label><input type="checkbox" name="inside" value="1"> {{ __($inside === 1 ? 'And the :count unit of description inside it' : 'And the :count units of description inside it', ['count' => $inside]) }}</label>
        @endif
        <button type="submit">{{ __('Apply') }}</button>
    </form>
@endsection
