{{-- OpenRiC Workspace - feedback received (roadmap phase 19a), after Heratio's feedback browse. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Feedback received'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Feedback received') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>@foreach([null => 'All', 'pending' => 'Pending', 'completed' => 'Completed'] as $key => $label)@if($status === $key)<strong>{{ __($label) }}</strong>@else<a href="{{ route('feedback.index', array_filter(['status' => $key])) }}">{{ __($label) }}</a>@endif @if($key) ({{ $counts[$key] ?? 0 }})@endif @if(! $loop->last) · @endif @endforeach</p>
    @if($entries->isEmpty())<p>{{ __('None.') }}</p>@else
    <table><thead><tr><th scope="col">{{ __('Subject') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Received') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
        <tbody>@foreach($entries as $f)<tr><td><a href="{{ route('feedback.show', $f) }}">{{ $f->subject }}</a></td><td>{{ $f->type->label($lang) }}</td>
            <td>@if($f->record)<a href="{{ route('records.show', $f->record) }}">{{ \App\Relations\EntityKinds::label($f->record, $lang) }}</a>@endif</td>
            <td>{{ $f->name }} {{ $f->surname }}</td><td>{{ $f->created_at->format('Y-m-d') }}</td><td>{{ $f->status->label($lang) }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
