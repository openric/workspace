{{-- OpenRiC Workspace - one call's trace: what was asked and answered, its receipt, the proposals it made and who reviewed them (Heratio's provenance trace; roadmap phase 17a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Receipt :n', ['n' => $inference->seq]))
@section('content')
    <h1>{{ __('Receipt :n', ['n' => $inference->seq]) }}</h1>
    <p><a href="{{ route('ai.inferences') }}">{{ __('The log of inferences') }}</a></p>
    <table><tbody>
        <tr><th scope="row">{{ __('Service') }}</th><td>{{ $inference->service->label(\App\Support\Vocab::languages()) }}</td></tr>
        <tr><th scope="row">{{ __('Model') }}</th><td><code>{{ $inference->model }}</code> {{ $inference->model_version !== 'unknown' ? $inference->model_version : '' }}</td></tr>
        <tr><th scope="row">{{ __('When') }}</th><td>{{ $inference->occurred_at->timezone(config('workspace.timezone'))->format('Y-m-d H:i:s') }}, {{ __(':ms ms', ['ms' => $inference->elapsed_ms]) }}</td></tr>
        <tr><th scope="row">{{ __('Asked by') }}</th><td>{{ $inference->user?->name ?? __('the Workspace') }}</td></tr>
        <tr><th scope="row">{{ __('About') }}</th><td>@if($subject instanceof \App\Models\Instantiation)<a href="{{ route('instantiations.show', $subject) }}">{{ __('An instantiation of :title', ['title' => $subject->record->described('title', ['en'])]) }}</a>@elseif($subject instanceof \App\Models\RecordResource)<a href="{{ route('records.show', $subject) }}">{{ $subject->described('title', ['en']) }}</a>@else{{ $inference->subject_table }}@endif{{ $inference->field ? ', '.$inference->field : '' }}</td></tr>
        <tr><th scope="row">{{ __('Answered') }}</th><td>{{ $inference->succeeded ? __('Yes') : __('No').': '.$inference->error }}</td></tr>
        @if($inference->confidence !== null)<tr><th scope="row">{{ __('Confidence') }}</th><td>{{ number_format($inference->confidence, 2) }}</td></tr>@endif
        <tr><th scope="row">{{ __('What went in') }}</th><td>{{ $inference->input_excerpt }}<br><code class="meta">sha256 {{ $inference->input_hash }}</code></td></tr>
        <tr><th scope="row">{{ __('What came out') }}</th><td style="white-space: pre-line">{{ $inference->output_excerpt }}@if($inference->output_hash)<br><code class="meta">sha256 {{ $inference->output_hash }}</code>@endif</td></tr>
        <tr><th scope="row">{{ __('Gateway address') }}</th><td><code>{{ $inference->endpoint }}</code></td></tr>
        <tr><th scope="row">{{ __('Receipt') }}</th><td class="meta">{{ __('Hash') }} <code>{{ $inference->entry_hash }}</code><br>{{ __('Before it') }} <code>{{ $inference->prev_hash }}</code><br>{{ __('Signed with key') }} <code>{{ $inference->kid }}</code></td></tr>
    </tbody></table>
    @if($siblings->isNotEmpty())<p class="meta">{{ __('The same page, read in other strips') }}: @foreach($siblings as $s)<a href="{{ route('ai.inference', $s) }}">{{ $s->seq }}</a>@if(! $loop->last), @endif @endforeach</p>@endif
    <h2>{{ __('What it proposed, and who reviewed it') }}</h2>
    @forelse($proposals as $p)
        <p><strong>{{ $p->kind->label(\App\Support\Vocab::languages()) }}</strong>, {{ $p->status->label(\App\Support\Vocab::languages()) }}@if($p->reviewedBy) {{ __('by :name on :date', ['name' => $p->reviewedBy->name, 'date' => $p->reviewed_at->format('Y-m-d')]) }}@endif.
            @if($p->accepted_value !== null && $p->accepted_value !== $p->value) {{ __('The reviewer corrected it before accepting it.') }}@endif
            @if($p->status->notation === 'proposed')<a href="{{ route('proposals.index') }}">{{ __('In the review queue') }}</a>@endif</p>
    @empty
        <p>{{ __('None: the other strips of its page, or a failed call, made none.') }}</p>
    @endforelse
@endsection
