{{-- OpenRiC Workspace - an OAIS information package, its files and events (Heratio's preservation_package; roadmap phase 15d). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $package->name)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($when = fn ($d) => $d?->timezone(config('workspace.timezone'))->format('Y-m-d H:i'))
    <h1>{{ $package->name }}</h1>
    <p>@if($package->record)<a href="{{ route('packages.index', $package->record) }}">{{ __('Information packages of :unit', ['unit' => \App\Relations\EntityKinds::label($package->record)]) }}</a>@endif</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <dl>
        <dt>{{ __('Kind') }}</dt><dd>{{ $package->type->label($lang) }} ({{ strtoupper($package->type->notation) }})</dd>
        <dt>{{ __('Status') }}</dt><dd>{{ $package->status->label($lang) }}@if($package->validated_at) · {{ __('validated :date', ['date' => $when($package->validated_at)]) }}@endif</dd>
        <dt>{{ __('Serialised as') }}</dt><dd>{{ $package->format->label($lang) }}, BagIt {{ $package->bagit_version }}, {{ strtoupper($package->manifest_algorithm) }}</dd>
        <dt>{{ __('Files') }}</dt><dd>{{ $package->object_count }}, {{ \Illuminate\Support\Number::fileSize((int) $package->total_size, 1) }}; {{ __('the package file') }} {{ \Illuminate\Support\Number::fileSize((int) $package->package_size, 1) }}</dd>
        <dt>SHA-256</dt><dd style="word-break: break-all">{{ $package->package_checksum }}</dd>
        @if($package->parent)<dt>{{ __('Made from') }}</dt><dd><a href="{{ route('packages.show', $package->parent) }}">{{ $package->parent->name }}</a> ({{ strtoupper($package->parent->type->notation) }})</dd>@endif
        @foreach($package->children as $child)<dt>{{ __('Carried on into') }}</dt><dd><a href="{{ route('packages.show', $child) }}">{{ $child->name }}</a> ({{ strtoupper($child->type->notation) }})</dd>@endforeach
        @foreach(['originator' => __('Producer'), 'submission_agreement' => __('Submission agreement'), 'retention_period' => __('Retention period'), 'description' => __('Description')] as $field => $label)
            @if($package->$field)<dt>{{ $label }}</dt><dd>{{ $package->$field }}</dd>@endif
        @endforeach
        <dt>{{ __('Built by') }}</dt><dd>{{ $package->createdBy?->name }}, {{ $when($package->built_at) }}</dd>
    </dl>
    @if($errs = $package->metadata['validation'] ?? null)<ul class="error">@foreach(array_slice($errs, 0, 50) as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    @php($act = fn ($action, $label) => '<form method="post" action="'.route('packages.act', [$package, $action]).'" style="display: inline">'.csrf_field().'<button type="submit">'.e($label).'</button></form>')
    <p>@if($package->storage_path)<a href="{{ route('packages.download', $package) }}">{{ __('Download') }}</a> · {!! $act('validate', __('Validate it again')) !!}@endif
        @if(in_array($package->type->notation, ['sip', 'aip'], true) && $package->storage_path){!! $act('derive', $package->type->notation === 'sip' ? __('Carry it on into an AIP') : __('Make a DIP from it')) !!}@endif
        {!! $act('remove', __('Remove the package')) !!}</p>
    <h2>{{ __('Its files') }}</h2>
    <table><thead><tr><th scope="col">{{ __('Path') }}</th><th scope="col">{{ __('Role') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">{{ __('Checksum') }}</th></tr></thead>
        <tbody>@foreach($package->files->sortBy('sequence') as $f)<tr><td style="word-break: break-all">{{ $f->relative_path }}@if($f->puid)<br><small>{{ $f->puid }}</small>@endif</td><td>{{ $f->role->label($lang) }}</td>
            <td>{{ \Illuminate\Support\Number::fileSize($f->file_size, 1) }}</td><td style="word-break: break-all"><small>{{ $f->checksum_value }}</small></td></tr>@endforeach</tbody></table>
    <h2>{{ __('Events') }}</h2>
    <table><thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('Outcome') }}</th><th scope="col">{{ __('By') }}</th></tr></thead>
        <tbody>@foreach($package->events->sortByDesc('occurred_at') as $e)<tr><td>{{ $when($e->occurred_at) }}</td><td>{{ $e->type->label($lang) }}@if($e->detail)<br><small>{{ $e->detail }}</small>@endif</td>
            <td>{{ $e->outcome->label($lang) }}@if($e->outcome_detail)<br><small style="word-break: break-all">{{ \Illuminate\Support\Str::limit($e->outcome_detail, 200) }}</small>@endif</td><td>{{ $e->agent_name }}</td></tr>@endforeach</tbody></table>
@endsection
