{{-- OpenRiC Workspace - what an account may do, from its roles and itself (Heratio's per-user ACL pages, read-only). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $account, $own, $roles (the roles that apply) and $kinds: kind => action => [anywhere: bool, rules: [grant, from, scope]]. --}}
@extends('layouts.app')
@section('title', $own ? 'What you may do' : 'What '.$account->name.' may do')
@section('content')
    <p>@if($own)<a href="{{ route('account.profile') }}">Your profile</a>@else<a href="{{ route('accounts.show', $account) }}">{{ $account->name }}</a>@endif</p>
    <h1>{{ $own ? 'What you may do' : 'What '.$account->name.' may do' }}</h1>
    <p>From {{ $roles->map->name()->join(', ', ' and ') }}{{ $own ? '' : ', and the permissions set for this account alone' }}. A rule for one repository or one entry comes before a rule for everything, and a refusal before a grant at the same level.</p>
    @foreach($kinds as $kind => $actions)
        <details class="area" @if($loop->first) open @endif>
            <summary><h2>{{ ucfirst($kind) }}</h2> <span class="meta">{{ collect($actions)->where('anywhere', true)->count() }} of {{ count($actions) }} allowed somewhere</span></summary>
            <table>
                <thead><tr><th>Action</th><th>Somewhere</th><th>Rules</th></tr></thead>
                <tbody>
                @foreach($actions as $action => ['anywhere' => $anywhere, 'rules' => $rules])
                    <tr><td>{{ str_replace('-', ' ', $action) }}</td><td>{{ $anywhere ? 'Yes' : 'No' }}</td>
                        <td>
                            @forelse($rules as $rule)
                                {{ $rule['grant'] ? 'Granted' : 'Refused' }} {{ $rule['scope'] }} by {{ $rule['from'] }}<br>
                            @empty
                                <span class="meta">None</span>
                            @endforelse
                        </td></tr>
                @endforeach
                </tbody>
            </table>
        </details>
    @endforeach
@endsection
