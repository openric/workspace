{{-- OpenRiC Workspace - one fixity run and its checks, failures first (Heratio's ahg-integrity run detail; roadmap phase 12a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Fixity run'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($tz = config('workspace.timezone'))
    <h1>{{ __('Fixity run of :date', ['date' => $run->started_at->timezone($tz)->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) }}</h1>
    <p><a href="{{ route('fixity.schedules') }}">{{ __('Fixity') }}</a></p>
    <p>{{ $run->schedule?->name ?? __('Every file') }} · {{ $run->trigger->label($lang) }}@if($run->user) ({{ $run->user->name }})@endif · {{ $run->status->label($lang) }}
        · {{ __(':checked files, :bytes, :changed changed, :missing missing', ['checked' => number_format($run->files_checked), 'bytes' => \Illuminate\Support\Number::fileSize($run->bytes_checked, 1), 'changed' => $run->files_changed, 'missing' => $run->files_missing]) }}
        @if($run->completed_at) · {{ __('ended :date', ['date' => $run->completed_at->timezone($tz)->timezone(config('workspace.timezone'))->format('Y-m-d H:i')]) }}@endif</p>
    @if($run->error_message)<p class="error" role="alert">{{ $run->error_message }}</p>@endif
    <table><thead><tr><th scope="col">{{ __('File') }}</th><th scope="col">{{ __('Outcome') }}</th><th scope="col">SHA-256</th><th scope="col">{{ __('Size') }}</th></tr></thead>
        <tbody>@foreach($checks as $c)<tr><td>@if($c->file)<a href="{{ route('instantiations.show', $c->file->instantiation_id) }}">{{ $c->file->original_name }}</a> ({{ $c->file->use->label($lang) }})@else<code>{{ $c->digital_file_id }}</code>@endif</td>
            <td>@if($c->outcome->notation !== 'pass')<strong>{{ $c->outcome->label($lang) }}</strong>@else{{ $c->outcome->label($lang) }}@endif</td>
            <td><code style="word-break: break-all">{{ $c->expected_sha256 }}</code>@if($c->computed_sha256 && $c->computed_sha256 !== $c->expected_sha256)<br>{{ __('found') }} <code style="word-break: break-all">{{ $c->computed_sha256 }}</code>@endif</td>
            <td>{{ number_format($c->expected_size) }}@if($c->found_size !== null && $c->found_size !== $c->expected_size) · {{ __('found :size', ['size' => number_format($c->found_size)]) }}@endif</td></tr>@endforeach</tbody></table>
    {{ $checks->links() }}
@endsection
