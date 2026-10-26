{{-- OpenRiC Workspace - what an account may do, from its roles and itself (Heratio's per-user ACL pages, read-only). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $account, $own, $roles (the roles that apply) and $kinds: kind => action => [anywhere: bool, rules: [grant, from, scope]]. --}}
@extends('layouts.app')
@section('title', $own ? 'What you may do' : 'What '.$account->name.' may do')
@section('content')
    <p>@if($own)<a href="{{ route('account.profile') }}">{{ __('Profile') }}</a>@else<a href="{{ route('accounts.show', $account) }}">{{ $account->name }}</a>@endif</p>
    <h1>{{ $own ? __('What you may do') : __('What :name may do', ['name' => $account->name]) }}</h1>
    <p>{{ $own ? __('From :roles.', ['roles' => $roles->map->name()->join(', ', ' '.__('and').' ')]) : __('From :roles, and the permissions set for this account alone.', ['roles' => $roles->map->name()->join(', ', ' '.__('and').' ')]) }} {{ __('A rule for one repository or one entry comes before a rule for everything, and a refusal before a grant at the same level.') }}</p>
    @foreach($kinds as $kind => $actions)
        <details class="area" @if($loop->first) open @endif>
            <summary><h2>{{ ucfirst($kind) }}</h2> <span class="meta">{{ __(':allowed of :count allowed somewhere', ['allowed' => collect($actions)->where('anywhere', true)->count(), 'count' => count($actions)]) }}</span></summary>
            <table>
                <thead><tr><th>{{ __('Action') }}</th><th>{{ __('Somewhere') }}</th><th>{{ __('Rules') }}</th></tr></thead>
                <tbody>
                @foreach($actions as $action => ['anywhere' => $anywhere, 'rules' => $rules])
                    <tr><td>{{ str_replace('-', ' ', $action) }}</td><td>{{ __($anywhere ? 'Yes' : 'No') }}</td>
                        <td>
                            @forelse($rules as $rule)
                                {{ __($rule['grant'] ? 'Granted' : 'Refused') }} {{ $rule['scope'] }} by {{ $rule['from'] }}<br>
                            @empty
                                <span class="meta">{{ __('None') }}</span>
                            @endforelse
                        </td></tr>
                @endforeach
                </tbody>
            </table>
        </details>
    @endforeach
@endsection
