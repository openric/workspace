{{-- OpenRiC Workspace - audit log. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Audit log'))
@section('content')
    <h1>{{ __('Audit log') }}</h1>
    <p>{{ __('Every change and sign-in in this institution, newest first. The log cannot be edited or deleted.') }}</p>
    <table>
        <thead><tr><th scope="col">{{ __('When (UTC)') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('By') }}</th><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Address') }}</th></tr></thead>
        <tbody>
        @foreach($rows as $row)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($row->occurred_at)->utc()->format('Y-m-d H:i:s') }}</td>
                <td>{{ $row->action }}</td>
                <td>{{ $row->actor ?? 'the system' }}</td>
                <td>{{ $row->subject_table }}</td>
                <td>{{ $row->ip }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $rows->links() }}
@endsection
