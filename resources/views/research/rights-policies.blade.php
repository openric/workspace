{{-- OpenRiC Workspace - the ODRL rights policies of a record for researchers, and the decisions taken under them (Heratio's ODRL policies page; roadmap phase 19j). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Rights policies').' - '.\App\Relations\EntityKinds::label($record))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <p><a href="{{ route('records.show', $record) }}">{{ \App\Relations\EntityKinds::label($record) }}</a></p>
    <h1>{{ __('Rights policies for researchers') }}</h1>
    @include('research._head')
    <p>{{ __('They guard the record\'s files in the public catalogue: use, and reproduce when a file is downloaded. A prohibition that holds denies; else a permission that holds allows; when there are permissions and none holds, it is denied. They only narrow what access, embargoes and rights already allow; who may change the record is not held by them.') }}</p>
    <ul>@forelse($policies as $p)
        @php($c = (array) $p->constraints)
        <li><strong>{{ $p->policyType->label($lang) }}</strong>: {{ $p->action->label($lang) }}
            @if(! empty($c['account_ids'])) · {{ __('for') }} {{ collect($c['account_ids'])->map(fn ($id) => $accounts[$id]->name ?? '?')->join(', ') }}@endif
            @if(! empty($c['date_from']) || ! empty($c['date_to'])) · {{ $c['date_from'] ?? '…' }} - {{ $c['date_to'] ?? '…' }}@endif
            @if(! empty($c['max_uses'])) · {{ __('at most :n uses', ['n' => $c['max_uses']]) }}@endif
            @if($p->note) · {{ $p->note }}@endif
            <form method="post" action="{{ route('research.rights-policies.save', [$record, $p]) }}" style="display: inline">@csrf<input type="hidden" name="remove" value="1"><button type="submit">{{ __('Remove') }}</button></form></li>
    @empty<li>{{ __('None: the record\'s files open as access, embargoes and rights allow.') }}</li>@endforelse</ul>
    <h2>{{ __('Add a policy') }}</h2>
    <form method="post" action="{{ route('research.rights-policies.save', $record) }}">@csrf
        <label for="o-type">{{ __('Rule') }}</label> @include('projects._select', ['id' => 'o-type', 'name' => 'policy_type', 'choices' => $types, 'current' => null, 'default' => 'permission'])
        <label for="o-action">{{ __('Action') }}</label> @include('projects._select', ['id' => 'o-action', 'name' => 'action', 'choices' => $actions, 'current' => null, 'default' => 'use'])<br>
        <label for="o-accounts">{{ __('For these accounts (e-mail addresses), or everyone') }}</label> <input id="o-accounts" name="accounts" size="60"><br>
        <label for="o-from">{{ __('From') }}</label> <input id="o-from" name="date_from" type="date"> <label for="o-to">{{ __('To') }}</label> <input id="o-to" name="date_to" type="date">
        <label for="o-max">{{ __('At most this many uses') }}</label> <input id="o-max" name="max_uses" type="number" min="1" style="width: 5rem"><br>
        <label for="o-note">{{ __('Note') }}</label> <input id="o-note" name="note" size="60"> <button type="submit">{{ __('Add') }}</button></form>
    @if($decisions->isNotEmpty())<h2>{{ __('Decisions') }}</h2><ul class="meta">@foreach($decisions as $d)<li>{{ $d->evaluated_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }} · {{ $d->user?->name ?? __('the public') }} · {{ $d->action->label($lang) }} · {{ $d->permitted ? __('permitted') : __('denied') }} · {{ $d->rationale }}</li>@endforeach</ul>@endif
@endsection
