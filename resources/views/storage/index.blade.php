{{-- OpenRiC Workspace - containers: search, filter, and where each is kept. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Storage'))
@section('content')
    <h1>Containers @include('description._ric', ['term' => 'openricx:Container'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('The boxes, folders and drawers records are kept in, and where each is kept. Storage is for staff: none of it is public.') }}</p>
    @include('storage._nav')
    <form method="get" action="{{ route('containers.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="q" style="margin-top: 0">{{ __('Name, identifier or barcode') }}</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
        <div><label for="type" style="margin-top: 0">{{ __('Container type') }}</label>
            <select id="type" name="type" style="font: inherit; padding: .5rem">
                <option value="">{{ __('Any') }}</option>
                @foreach($types as $t)<option value="{{ $t->id }}" @selected($type === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
            </select></div>
        <div><label style="margin-top: 0"><input type="checkbox" name="unplaced" value="1" @checked($unplaced)> {{ __('Kept nowhere yet') }}</label></div>
        <button type="submit" style="margin-top: 0">{{ __('Search') }}</button>
    </form>
    @if($containers->isNotEmpty())
        <table>
            <thead><tr><th>{{ __('Container') }}</th><th>{{ __('Type') }}</th><th>{{ __('Kept in') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @foreach($containers as $container)
                @php($place = $storage->place($container))
                <tr><td><a href="{{ route('containers.show', $container) }}">{{ $container->described('container-name', [app()->getLocale(), 'en']) }}</a>@if($container->identifier) ({{ $container->identifier }})@endif</td>
                    <td>{{ $container->containerType?->anyLabel(['en']) }}</td>
                    <td>{{ $place ? $storage->path($place) : 'nowhere yet' }}</td>
                    <td>{{ $container->status?->label(['en']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p>{{ $query !== '' || $type !== '' || $unplaced ? 'No container matches.' : 'No containers yet.' }}</p>
    @endif
    @if($containers->hasPages())
        <nav aria-label="{{ __('Pages') }}"><p>
            @if($containers->previousPageUrl())<a href="{{ $containers->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
            Page {{ $containers->currentPage() }} of {{ $containers->lastPage() }}
            @if($containers->nextPageUrl())<a href="{{ $containers->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
        </p></nav>
    @endif
@endsection
