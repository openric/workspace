{{-- OpenRiC Workspace - audit log. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Audit log')
@section('content')
    <h1>Audit log</h1>
    <p>Every change and sign-in in this institution, newest first. The log cannot be edited or deleted.</p>
    <table>
        <thead><tr><th scope="col">When (UTC)</th><th scope="col">Action</th><th scope="col">By</th><th scope="col">Record</th><th scope="col">Address</th></tr></thead>
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
