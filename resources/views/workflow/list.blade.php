{{-- OpenRiC Workspace - my tasks, the pool or team work (Heratio's my tasks and pool, PSIS's team work). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', $title)
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    <h1>{{ $title }}</h1>
    @include('workflow._nav')
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if(session('failed'))<ul class="error">@foreach(session('failed') as $id => $why)<li>{{ $why }}</li>@endforeach</ul>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    @isset($statuses)
        <form method="get" style="display: flex; gap: .5rem; align-items: end">
            <div><label for="status" style="margin-top: 0">{{ __('Status') }}</label>
                <select id="status" name="status" style="font: inherit; padding: .5rem"><option value="">{{ __('Open') }}</option>@foreach($statuses as $s)<option value="{{ $s->notation }}" @selected($status === $s->notation)>{{ $s->label($lang) }}</option>@endforeach</select></div>
            <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
        </form>
    @endisset
    @isset($roles)
        <form method="get" style="display: flex; gap: .5rem; align-items: end">
            <div><label for="role" style="margin-top: 0">{{ __('Team') }}</label>
                <select id="role" name="role" style="font: inherit; padding: .5rem"><option value="">{{ __('Everyone') }}</option>@foreach($roles as $r)<option value="{{ $r->id }}" @selected($role?->id === $r->id)>{{ $r->name($lang) }}</option>@endforeach</select></div>
            <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
        </form>
        <p><strong>{{ __('Open tasks') }}:</strong> {{ $tasks->count() }}@foreach($byAssignee as $who => $n) · {{ $who }}: {{ $n }}@endforeach</p>
    @endisset
    @if($pool ?? false)<p class="meta">{{ __('Tasks no one has yet, of steps you may take, on records you may change. Open one to claim it.') }}</p>@endif
    @include('workflow._tasks', ['tasks' => $tasks])
@endsection
