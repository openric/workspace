{{-- OpenRiC Workspace - the Dropdown manager: every bundled vocabulary the forms offer (Heratio's dropdown manager; roadmap phase 20d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Dropdown manager'))
@section('content')
    <h1>{{ __('Dropdown manager') }}</h1>
    <p>{{ __('The lists the forms offer: statuses, types, kinds and the rest, each a vocabulary that ships with the Workspace. Give any list your own wording in each language, put its terms in your order and choose a default; in an open list, add terms of your own and hide the ones you do not use. What you change is for this institution alone, and every term keeps its address and RiC mapping, so exports do not change.') }}</p>
    <p class="meta">{{ __('Subjects, places, levels and the other lists you describe with are under Vocabularies.') }} <a href="{{ route('vocabularies.index') }}">{{ __('Vocabularies') }}</a></p>
    <form method="get" action="{{ route('dropdowns.index') }}" role="search"><label for="dq">{{ __('Find a list') }}</label><input id="dq" name="q" value="{{ $q }}"> <button type="submit">{{ __('Find') }}</button></form>
    <table><thead><tr><th scope="col">{{ __('List') }}</th><th scope="col">{{ __('Open') }}</th><th scope="col">{{ __('Changed here') }}</th></tr></thead>
        <tbody>@forelse($vocabularies as $v)<tr><td><a href="{{ route('dropdowns.edit', $v) }}">{{ str_replace('OpenRiC Workspace ', '', (string) $v->title) }}</a> <span class="meta">{{ \App\Vocabulary\Dropdowns::name($v) }}</span></td>
            <td>{{ $dropdowns->isOpen(\App\Vocabulary\Dropdowns::name($v)) ? __('Yes') : __('No') }}</td>
            <td>@if(($changed[$v->id] ?? 0) || ($own[$v->id] ?? 0)){{ trans_choice(':count term changed|:count terms changed', $changed[$v->id] ?? 0, ['count' => $changed[$v->id] ?? 0]) }}@if($own[$v->id] ?? 0), {{ trans_choice(':count of our own|:count of our own', $own[$v->id], ['count' => $own[$v->id]]) }}@endif @endif</td></tr>
        @empty<tr><td colspan="3">{{ __('No list matches.') }}</td></tr>@endforelse</tbody></table>
@endsection
