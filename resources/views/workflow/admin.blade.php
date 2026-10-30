{{-- OpenRiC Workspace - the institution's workflows (Heratio's workflow admin). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Workflows'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ __('Workflows') }}</h1>
    @include('workflow._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p><a href="{{ route('workflow.admin.create') }}">{{ __('Add a workflow') }}</a></p>
    @forelse($workflows as $w)
        <p style="margin: .3rem 0"><a href="{{ route('workflow.admin.edit', $w) }}"><strong>{{ $w->name }}</strong></a>
            ({{ collect([$w->scope->label($lang).($w->scopeRepository ? ': '.$w->scopeRepository->described('authorised-name', $lang) : '').($w->scopeRecord ? ': '.$w->scopeRecord->described('title', $lang) : ''),
                $w->trigger->label($lang), $w->is_default ? __('default') : null, $w->is_active ? null : __('inactive'),
                trans_choice(':count step|:count steps', $w->steps_count, ['count' => $w->steps_count]), trans_choice(':count open task|:count open tasks', $w->open_count, ['count' => $w->open_count])])->filter()->join(', ') }})</p>
    @empty
        <p>{{ __('No workflows yet.') }}</p>
    @endforelse
@endsection
