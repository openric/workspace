{{-- OpenRiC Workspace - researchers (Heratio's research admin researchers list; roadmap phase 19b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Researchers'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Researchers') }}</h1>
    <p>@foreach($statuses as $s)@if($status === $s->notation)<strong>{{ $s->label($lang) }}</strong>@else<a href="{{ route('reading-room.researchers', ['status' => $s->notation]) }}">{{ $s->label($lang) }}</a>@endif ({{ $counts[$s->notation] ?? 0 }}) · @endforeach @if($status === 'all')<strong>{{ __('All') }}</strong>@else<a href="{{ route('reading-room.researchers', ['status' => 'all']) }}">{{ __('All') }}</a>@endif</p>
    @if($researchers->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Affiliation') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Until') }}</th><th scope="col">{{ __('Registered') }}</th></tr></thead>
        <tbody>@foreach($researchers as $r)<tr><td><a href="{{ route('reading-room.researchers.show', $r) }}">{{ $r->last_name }}, {{ $r->first_name }}</a></td><td>{{ $r->institution }}</td><td>{{ $r->type?->name }}</td>
            <td>{{ $r->status->label($lang) }}</td><td>{{ $r->expires_on?->format('Y-m-d') }}</td><td>{{ $r->created_at->format('Y-m-d') }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
