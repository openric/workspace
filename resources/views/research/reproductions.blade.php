{{-- OpenRiC Workspace - reproduction requests (Heratio's reproductions list; roadmap phase 19c): one's own, or for staff every request of the institution. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Reproductions'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Reproductions') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($staff)<p><a href="{{ route('research.reproductions.index') }}">{{ __('All') }}</a>@foreach($statuses as $s) · <a href="{{ route('research.reproductions.index', ['status' => $s->notation]) }}" @if($status === $s->notation) aria-current="page" @endif>{{ $s->label($lang) }}</a>@endforeach</p>@endif
    @if($requests->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Reference') }}</th>@if($staff)<th scope="col">{{ __('Researcher') }}</th>@endif<th scope="col">{{ __('Asked on') }}</th><th scope="col">{{ __('Cost') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@foreach($requests as $r)<tr><td><a href="{{ route('research.reproductions.show', $r) }}">{{ $r->reference }}</a></td>@if($staff)<td>{{ $r->researcher->first_name }} {{ $r->researcher->last_name }}</td>@endif
            <td>{{ $r->created_at->format('Y-m-d') }}</td><td>@if($r->final_cost ?? $r->estimated_cost){{ $r->currency }} {{ number_format((float) ($r->final_cost ?? $r->estimated_cost), 2) }}@endif</td><td>{{ $r->status->label($lang) }}</td></tr>@endforeach</tbody></table>
    @endif
    @if(app(\App\Research\Researchers::class)->active($researcher))
        <details><summary>{{ __('Ask for reproductions') }}</summary>
            <form method="post" action="{{ route('research.reproductions.store') }}">@csrf
                <p><label for="rp-purpose">{{ __('Purpose') }}</label><br><textarea id="rp-purpose" name="purpose" rows="2" cols="70"></textarea></p>
                <p><label for="rp-use">{{ __('Intended use') }}</label><br><textarea id="rp-use" name="intended_use" rows="2" cols="70"></textarea></p>
                <p><label for="rp-pub">{{ __('Publication details (if they are to be published)') }}</label><br><textarea id="rp-pub" name="publication_details" rows="2" cols="70"></textarea></p>
                <p><label for="rp-delivery">{{ __('Delivery') }}</label> <select id="rp-delivery" name="delivery_method">@foreach($deliveries as $d)<option value="{{ $d->notation }}">{{ $d->label($lang) }}</option>@endforeach</select>
                    <label for="rp-email">{{ __('Email') }}</label> <input id="rp-email" name="delivery_email" type="email" value="{{ auth()->user()->email }}"></p>
                <p><label for="rp-address">{{ __('Address (for post or courier)') }}</label><br><textarea id="rp-address" name="delivery_address" rows="2" cols="70"></textarea></p>
                <button type="submit">{{ __('Start the request') }}</button></form>
        </details>
    @elseif(! $staff)<p>{{ __('Register as a researcher, and be approved, to ask for reproductions.') }} <a href="{{ route('research.profile') }}">{{ __('Researcher') }}</a></p>@endif
@endsection
