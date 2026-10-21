{{-- OpenRiC Workspace - requests for an account, to approve or reject (Heratio's registration pending). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Requests for an account')
@section('content')
    <p><a href="{{ route('accounts.index') }}">Accounts</a></p>
    <h1>Requests for an account</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @unless(\App\Models\Institution::current()?->registration_open)<p class="meta">Visitors cannot ask for an account now; turn it on in <a href="{{ route('settings.edit') }}">Settings</a>.</p>@endunless

    <h2>Waiting ({{ $pending->count() }})</h2>
    @forelse($pending as $registration)
        <section style="border: 1px solid var(--line); border-radius: var(--radius); padding: .5rem 1rem; margin: .75rem 0">
            <h3 style="margin: .3rem 0">{{ $registration->name }}</h3>
            <p style="margin: .2rem 0">{{ $registration->email }} · asked {{ $registration->created_at->format('Y-m-d H:i') }}</p>
            @if($registration->reason)<p style="margin: .4rem 0">{{ $registration->reason }}</p>@endif
            <form method="post" action="{{ route('registrations.approve', $registration) }}">
                @csrf
                <fieldset><legend>Roles of the new account</legend>
                    @foreach($roles as $role)<label style="font-weight: normal; margin-top: .3rem"><input type="checkbox" name="roles[]" value="{{ $role->id }}" style="width: auto"> {{ $role->name() }}</label>@endforeach
                </fieldset>
                <button type="submit">Approve</button>
            </form>
            <form method="post" action="{{ route('registrations.reject', $registration) }}">@csrf<button type="submit" aria-pressed="false">Reject</button></form>
        </section>
    @empty
        <p>None.</p>
    @endforelse

    @if($decided->isNotEmpty())
        <h2>Decided</h2>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Decision</th><th>By</th></tr></thead>
            <tbody>@foreach($decided as $registration)<tr><td>{{ $registration->name }}</td><td>{{ $registration->email }}</td>
                <td>{{ $registration->approved_at ? 'Approved '.$registration->approved_at->format('Y-m-d') : 'Rejected '.$registration->rejected_at->format('Y-m-d') }}</td>
                <td>{{ $registration->decidedBy?->name }}</td></tr>@endforeach</tbody>
        </table>
    @endif
@endsection
