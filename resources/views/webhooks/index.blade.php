{{-- OpenRiC Workspace - webhooks and their deliveries (Heratio's ahg-api webhooks; roadmap phase 22c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Webhooks'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    <h1>{{ __('Webhooks') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if(session('secret'))<p role="status"><strong>{{ __('The secret, shown this once:') }}</strong> <code>{{ session('secret') }}</code></p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <p>{{ __('A webhook tells another system that an entity was created, changed, deleted, published or withdrawn. It sends what happened, the entity\'s persistent IRI and its API address, never what it says: the receiver reads that through the API with its own key. Each delivery is signed in the X-OpenRiC-Signature header (HMAC-SHA256 of the body with the secret), tried again after 1, 2, 4, 8 and 16 minutes, and a webhook that fails five deliveries is left alone until it is saved again.') }}</p>
    @foreach($hooks as $h)
        <details><summary>{{ $h->name }} · <code>{{ $h->url }}</code>@unless($h->is_active) · <strong>{{ __('off') }}</strong>@endunless @if($h->failure_count >= 5) · <strong>{{ __('stopped after five failures') }}</strong>@endif</summary>
            <form method="post" action="{{ route('webhooks.update', $h) }}">@csrf @include('webhooks._fields', ['h' => $h, 'x' => 'h'.$loop->index])
                <label><input type="checkbox" name="new_secret" value="1"> {{ __('make a new secret') }}</label> <button type="submit">{{ __('Save') }}</button></form></details>
    @endforeach
    <details><summary>{{ __('Add a webhook') }}</summary><form method="post" action="{{ route('webhooks.store') }}">@csrf @include('webhooks._fields', ['h' => null, 'x' => 'hn'])<button type="submit">{{ __('Add') }}</button></form></details>
    <h2>{{ __('Deliveries') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Webhook') }}</th><th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('Entity') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@forelse($deliveries as $d)<tr><td>{{ $d->created_at->timezone($tz)->timezone(config('workspace.timezone'))->format('Y-m-d H:i:s') }}</td><td>{{ $d->webhook->name }}</td><td>{{ $d->event->label($lang) }}</td>
            <td>{{ $d->entity_kind }} <code>{{ $d->payload['iri'] ?? $d->entity_id }}</code></td>
            <td>{{ $d->status->label($lang) }}@if($d->response_code) · {{ $d->response_code }}@endif · {{ trans_choice(':count attempt|:count attempts', $d->attempt_count, ['count' => $d->attempt_count]) }}@if($d->next_retry_at) · {{ __('next :time', ['time' => $d->next_retry_at->timezone($tz)->format('H:i')]) }}@endif</td></tr>
        @empty<tr><td colspan="5">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
