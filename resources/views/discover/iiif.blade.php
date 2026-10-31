{{-- OpenRiC Workspace - a record's IIIF manifest in Mirador (roadmap phase 14, ADR 0014), after Heratio's iiif/viewer. A Content State in ?iiif-content= opens it at its canvas; a saved layout (?workspace=) opens with all its windows, and a signed-in reader may save the windows open now (Heratio's workspaces). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $view->label($unit))
@section('head')<link rel="stylesheet" href="/vendor/ahg-theme-b5/js/vendor/mirador/mirador.min.css">@endsection
@section('content')
    <p><a href="{{ route('discover.record', $unit->id) }}">{{ __('Back to :name', ['name' => $view->label($unit)]) }}</a> · <a href="{{ $manifest }}">{{ __('IIIF manifest') }}</a>
        @if($signedIn) · <a href="{{ route('iiif-workspaces.index') }}">{{ __('My viewer layouts') }}</a>@if($default) · <a href="{{ route('iiif-workspaces.open', $default) }}">{{ __('Open my default layout') }}</a>@endif @endif</p>
    <h1>{{ $view->label($unit) }}</h1>
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <div id="mirador" style="position: relative; height: 75vh; min-height: 420px" aria-label="{{ __('Mirador viewer') }}"></div>
    @if($signedIn)
        <form method="post" action="{{ route('iiif-workspaces.store') }}" id="save-layout" style="margin-top: .5rem">@csrf
            <input type="hidden" name="windows" id="layout-windows">
            <label for="layout-name">{{ __('Save these windows as') }}</label> <input id="layout-name" name="name" required maxlength="255" size="30">
            <button type="submit">{{ __('Save layout') }}</button>
        </form>
    @endif
    <script src="/vendor/ahg-theme-b5/js/vendor/mirador/mirador.min.js"></script>
    <script>
        var viewer = Mirador.viewer({
            id: 'mirador',
            windows: @json($windows, JSON_UNESCAPED_SLASHES),
            window: { allowClose: {{ count($windows) > 1 || $signedIn ? 'true' : 'false' }}, allowMaximize: false, sideBarOpen: false },
            workspaceControlPanel: { enabled: {{ $signedIn ? 'true' : 'false' }} },
        });
        var form = document.getElementById('save-layout');
        if (form) {
            form.addEventListener('submit', function () {
                var windows = viewer.store.getState().windows || {};
                document.getElementById('layout-windows').value = JSON.stringify(Object.keys(windows).map(function (k) {
                    return { manifestId: windows[k].manifestId, canvasId: windows[k].canvasId };
                }));
            });
        }
    </script>
@endsection
