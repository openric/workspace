{{-- OpenRiC Workspace - every change to one interface string (Heratio's string history; roadmap phase 17c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('History'))
@section('content')
    <p><a href="{{ route('translation.strings', ['locale' => $locale]) }}">{{ __('Interface wording') }}</a></p>
    <h1>{{ $key }} <span class="meta">({{ $locale }})</span></h1>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('To') }}</th><th scope="col">{{ __('By') }}</th><th scope="col">{{ __('State') }}</th></tr></thead><tbody>
        @forelse($changes as $c)<tr><td>{{ \Illuminate\Support\Carbon::parse($c->submitted_at)->format('Y-m-d H:i') }}</td><td>{{ $c->old_value ?? __('(the Workspace\'s)') }}</td><td>{{ $c->new_value ?? __('(the Workspace\'s)') }}</td>
            <td>{{ $c->submitted_by }}@if($c->reviewed_by && $c->reviewed_by !== $c->submitted_by), {{ __('reviewed by :name', ['name' => $c->reviewed_by]) }}@endif</td><td>{{ $c->status }}</td></tr>
        @empty<tr><td colspan="5">{{ __('Never changed.') }}</td></tr>@endforelse
    </tbody></table>
@endsection
