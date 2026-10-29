{{-- OpenRiC Workspace - donors: the authority records that gave an accession (Heratio's donor browse). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Donors'))
@section('content')
    <p><a href="{{ route('accessions.index') }}">{{ __('Accessions') }}</a></p>
    <h1>{{ __('Donors') }}</h1>
    <p>{{ __('A donor is an authority record that took part in an accession in the donor role. Its contact points are on its own page.') }}</p>
    @forelse($donors as $donor)
        <p><a href="{{ route('agents.show', $donor) }}">{{ $donor->described('agent-authorised-name', [app()->getLocale(), 'en']) }}</a>, {{ trans_choice(':count accession|:count accessions', $counts[$donor->id], ['count' => $counts[$donor->id]]) }}</p>
    @empty
        <p>{{ __('No donors yet: link one from an accession.') }}</p>
    @endforelse
@endsection
