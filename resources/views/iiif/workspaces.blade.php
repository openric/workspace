{{-- OpenRiC Workspace - the viewer layouts this account saved (roadmap phase 14b, ADR 0014), after Heratio's workspaces page: open, rename, choose the default, delete. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('My viewer layouts'))
@section('content')
    <h1>{{ __('My viewer layouts') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('Save the windows open in the IIIF viewer of the public catalogue, with the page each shows, and open them again together. Only you see them.') }}</p>
    @if($workspaces->isEmpty())<p>{{ __('No layouts saved yet. Open a record in Mirador from its public page, and save the windows there.') }}</p>@else
    <table>
        <thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Windows') }}</th><th scope="col">{{ __('Default') }}</th><th scope="col"></th></tr></thead>
        <tbody>@foreach($workspaces as $w)
            <tr><td><a href="{{ route('iiif-workspaces.open', $w) }}">{{ $w->name }}</a></td><td>{{ count($w->windows) }}</td><td>{{ $w->is_default ? __('Yes') : '' }}</td>
                <td><details><summary>{{ __('Change') }}</summary>
                    <form method="post" action="{{ route('iiif-workspaces.update', $w) }}">@csrf
                        <label for="name-{{ $w->id }}">{{ __('Name') }}</label> <input id="name-{{ $w->id }}" name="name" value="{{ $w->name }}" maxlength="255">
                        <label><input type="hidden" name="default" value="0"><input type="checkbox" name="default" value="1" @checked($w->is_default)> {{ __('Default') }}</label>
                        <button type="submit">{{ __('Save') }}</button></form>
                    <form method="post" action="{{ route('iiif-workspaces.destroy', $w) }}">@csrf<button type="submit">{{ __('Delete') }}</button></form>
                </details></td></tr>
        @endforeach</tbody>
    </table>@endif
@endsection
