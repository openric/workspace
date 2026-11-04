{{-- OpenRiC Workspace - a migration plan, its approval, its run and its files (Heratio's preservation_migration_plan; roadmap phase 15c). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $plan->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d?->timezone(config('workspace.timezone'))->format('Y-m-d H:i'))
    @php($status = $plan->status->notation)
    <h1>{{ $plan->name }}</h1>
    <p><a href="{{ route('preservation.index') }}#plans">{{ __('Migration plans') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    @if($plan->description)<p>{{ $plan->description }}</p>@endif
    <dl>
        <dt>{{ __('Status') }}</dt><dd>{{ $plan->status->label($lang) }}</dd>
        <dt>{{ __('Pathway') }}</dt><dd>{{ $plan->source_puid }} → {{ $plan->target_puid }}, {{ $plan->pathway->tool->label($lang) }}, {{ __('quality: :q', ['q' => $plan->pathway->qualityImpact->label($lang)]) }}</dd>
        <dt>{{ __('Scope') }}</dt><dd>@if($plan->record){{ \App\Relations\EntityKinds::label($plan->record) }} {{ __('and what it includes') }}@elseif($plan->repository){{ \App\Relations\EntityKinds::label($plan->repository) }}@else{{ __('Every repository') }}@endif ·
            {{ trans_choice(':count file of the format in scope now|:count files of the format in scope now', $inScope, ['count' => $inScope]) }}</dd>
        <dt>{{ __('Drawn up by') }}</dt><dd>{{ $plan->createdBy?->name }}, {{ $when($plan->created_at) }}</dd>
        @if($plan->approvedBy)<dt>{{ __('Approved by') }}</dt><dd>{{ $plan->approvedBy->name }}, {{ $when($plan->approved_at) }}</dd>@endif
        @if($plan->scheduled_start)<dt>{{ __('Starts') }}</dt><dd>{{ $when($plan->scheduled_start) }}</dd>@endif
        @if($plan->started_at)<dt>{{ __('Run') }}</dt><dd>{{ $when($plan->started_at) }}@if($plan->completed_at) {{ __('to :date', ['date' => $when($plan->completed_at)]) }}@endif</dd>@endif
        <dt>{{ __('Files') }}</dt><dd>{{ __(':total in all: :completed migrated, :failed failed, :skipped skipped, :pending waiting', $counts) }}@if($counts['converted_bytes']) · {{ \Illuminate\Support\Number::fileSize($counts['original_bytes'], 1) }} → {{ \Illuminate\Support\Number::fileSize($counts['converted_bytes'], 1) }}@endif</dd>
    </dl>
    @php($act = fn ($action, $label) => '<form method="post" action="'.route('preservation.plans.action', [$plan, $action]).'" style="display: inline">'.csrf_field().'<button type="submit">'.e($label).'</button></form>')
    <p>@if($status === 'draft'){!! $act('approve', __('Approve the plan')) !!}@endif
        @if($status === 'approved'){!! $act('start', __('Run it now')) !!}@endif
        @if($status === 'failed'){!! $act('retry', __('Try the failed files again')) !!}@endif
        @unless(in_array($status, ['completed', 'cancelled'], true)){!! $act('cancel', __('Cancel the plan')) !!}@endunless</p>
    @if($status === 'draft')
        <p class="meta">{{ __('A plan is approved by someone other than who drew it up. The master of each file is kept; its migration is a preservation master beside it.') }}</p>
        <details><summary>{{ __('Change the plan') }}</summary><form method="post" action="{{ route('preservation.plans.update', $plan) }}">@csrf @include('preservation._plan', ['plan' => $plan])<button type="submit">{{ __('Save') }}</button></form></details>
    @endif
    @if($items->isNotEmpty())
        <h2>{{ __('Its files') }}</h2>
        <table><thead><tr><th scope="col">{{ __('File') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Tries') }}</th></tr></thead>
            <tbody>@foreach($items as $item)<tr><td>@if($item->file)<a href="{{ route('instantiations.show', $item->file->instantiation_id) }}">{{ $item->file->original_name }}</a>@else{{ __('a file since removed') }}@endif</td>
                <td>{{ $item->status->label($lang) }}@if($item->error_message)<br><small>{{ \Illuminate\Support\Str::limit($item->error_message, 200) }}</small>@endif</td><td>{{ $item->retry_count + 1 }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
