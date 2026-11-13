{{-- OpenRiC Workspace - the staff dashboard (phase 26): the work waiting rather than a list of links. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Home'))
@section('content')
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 1rem 2rem">
        <div>
            <h1 class="title" style="margin: 0">{{ $user->institution->name }}</h1>
            <p class="meta" style="margin: .3rem 0 0">{{ __('Signed in as :name (:email).', ['name' => $user->name, 'email' => $user->email]) }}</p>
        </div>
        <div class="actions" style="margin: 0">
            @if($user->mayAnywhere('create', 'description'))<a class="button" href="{{ route('records.create') }}">{{ __('New description') }}</a>@endif
            @if($user->mayAnywhere('create', 'description'))<a class="button-secondary" href="{{ route('imports.create') }}">{{ __('Import') }}</a>@endif
            @if($user->mayAnywhere('read', 'description'))<a class="button-tertiary" href="{{ route('records.index') }}">{{ __('Archival descriptions') }}</a>@endif
        </div>
    </div>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif

    <div class="tiles">
        @if($proposalCount !== null)
            <a class="tile" href="{{ route('proposals.index') }}"><span class="tile-label">{{ __('Proposals to review') }}</span><span class="tile-figure">{{ $proposalCount }}</span></a>
        @endif
        <a class="tile" href="{{ route('workflow.mine') }}"><span class="tile-label">{{ __('My tasks') }}</span><span class="tile-figure">{{ $tasks }}</span></a>
        @if($descriptions !== null)
            <a class="tile" href="{{ route('records.index') }}"><span class="tile-label">{{ __('Archival descriptions') }}</span><span class="tile-figure">{{ number_format($descriptions) }}</span></a>
        @endif
        <a class="tile" href="{{ route('jobs.index') }}"><span class="tile-label">{{ __('Jobs') }}</span><span class="tile-figure">{{ $jobs }}</span></a>
        @if($problems !== null)
            <a class="tile" href="{{ route('operations.index') }}"><span class="tile-label">{{ __('Operations') }}</span>
                @if($problems === [])<span class="badge ok" style="align-self: flex-start; margin-top: .45rem">{{ __('Nothing needs attention.') }}</span>
                @else<span class="tile-figure" style="color: var(--error)">{{ count($problems) }}</span>@endif</a>
        @endif
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start">
        <section class="panel" style="flex: 999 1 32rem; min-width: 0">
            <h2>{{ __('Recent changes') }}</h2>
            @forelse($recent as $record)
                <div class="row-line">
                    <div style="min-width: 0"><a href="{{ route('records.show', $record) }}" style="font-weight: 600">{{ $record->described('title', [app()->getLocale(), 'en']) ?: '[Untitled]' }}</a>
                        <div class="meta">{{ $record->level?->label(\App\Support\Vocab::languages()) }}</div></div>
                    <span class="meta" style="white-space: nowrap">{{ $record->updated_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i') }}</span>
                </div>
            @empty
                <p>{{ __('None.') }}</p>
            @endforelse
            @can(\App\Support\Vocab::permission('view-audit-log'))<p style="margin-bottom: 0"><a class="button-tertiary" href="{{ route('audit') }}">{{ __('Audit log') }}</a></p>@endcan
        </section>

        <div style="flex: 1 1 20rem; min-width: 0">
            @if($proposalCount !== null)
                <section class="panel" style="margin-top: 1rem">
                    <h2>{{ __('Proposals to review') }}</h2>
                    @forelse($proposals as $proposal)
                        <div class="row-line" style="display: block"><div>{{ \Illuminate\Support\Str::limit((string) $proposal->value, 70) }}</div><div class="meta">{{ $proposal->record?->described('title', ['en']) }}</div></div>
                    @empty
                        <p>{{ __('None.') }}</p>
                    @endforelse
                    @if($proposalCount)<p style="margin-bottom: 0"><a class="button" href="{{ route('proposals.index') }}">{{ __('Review') }}</a></p>@endif
                </section>
            @endif
            <section class="panel">
                <h2>{{ __('Your roles') }}</h2>
                @forelse($user->roles as $role)
                    <span class="badge" style="margin: 0 .3rem .3rem 0">{{ $role->name() }}</span>
                @empty
                    <p>{{ __('You have no roles of your own yet, so you can do only what every signed-in account may. An administrator can give you a role.') }}</p>
                @endforelse
                <p style="margin-bottom: 0"><a href="{{ route('account.profile') }}">{{ __('Profile') }}</a> · <a href="{{ route('password.edit') }}">{{ __('Change password') }}</a></p>
            </section>
        </div>
    </div>
@endsection
