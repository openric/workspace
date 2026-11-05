{{-- OpenRiC Workspace - parked names, waiting for more to go on (Heratio's park queue; roadmap phase 17b2). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Parked names'))
@section('content')
    <p><a href="{{ route('authority.queue') }}">{{ __('Names to resolve') }}</a></p>
    <h1>{{ __('Parked names') }}</h1>
    <p>{{ __('Names set aside until there is more to go on. Each night the Workspace looks for their candidates again, and flags a name when one has appeared that was not there before.') }}</p>
    <form method="get" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="pk-by" style="margin-top: 0">{{ __('Parked by') }}</label><select id="pk-by" name="parked_by"><option value="">{{ __('Anyone') }}</option>@foreach($archivists as $a)<option value="{{ $a->id }}" @selected(($filters['parked_by'] ?? '') === $a->id)>{{ $a->name }} ({{ $counts[$a->id] ?? 0 }})</option>@endforeach</select></div>
        <div><label for="pk-kind" style="margin-top: 0">{{ __('Kind') }}</label><select id="pk-kind" name="kind"><option value="">{{ __('Any') }}</option><option value="agent" @selected(($filters['kind'] ?? '') === 'agent')>{{ __('People and organisations') }}</option><option value="place" @selected(($filters['kind'] ?? '') === 'place')>{{ __('Places') }}</option></select></div>
        <div><label for="pk-reason" style="margin-top: 0">{{ __('Reason contains') }}</label><input id="pk-reason" name="reason" value="{{ $filters['reason'] ?? '' }}"></div>
        <div><label for="pk-since" style="margin-top: 0">{{ __('Parked since') }}</label><input id="pk-since" name="since" type="date" value="{{ $filters['since'] ?? '' }}"></div>
        <div><label style="margin-top: 0"><input type="checkbox" name="new_only" value="1" style="width: auto" @checked($filters['new_only'])> {{ __('With a new candidate') }}</label></div>
        <div><label for="pk-sort" style="margin-top: 0">{{ __('Sort') }}</label><select id="pk-sort" name="sort"><option value="">{{ __('Newest first') }}</option><option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>{{ __('Oldest first') }}</option><option value="new" @selected(($filters['sort'] ?? '') === 'new')>{{ __('New candidate first') }}</option></select></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    <table><thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Why') }}</th><th scope="col">{{ __('Parked') }}</th><th scope="col">{{ __('Candidates') }}</th></tr></thead><tbody>
        @forelse($parks as $p)<tr><td><a href="{{ route('authority.review', $p->mention) }}">{{ $p->mention->value() }}</a>@if($p->new_candidate_available) <strong>{{ __('new candidate') }}</strong>@endif</td>
            <td>{{ $p->reason }}</td><td>{{ __('by :name on :date', ['name' => $p->parkedBy->name, 'date' => $p->parked_at->format('Y-m-d')]) }}</td><td>{{ $p->mention->candidates_count }}</td></tr>
        @empty<tr><td colspan="4">{{ __('No names are parked.') }}</td></tr>@endforelse
    </tbody></table>
    {{ $parks->links() }}
@endsection
