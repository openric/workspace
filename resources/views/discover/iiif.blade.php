{{-- OpenRiC Workspace - a record's IIIF manifest in Mirador (roadmap phase 14a, ADR 0014), after Heratio's iiif/viewer. A Content State in ?iiif-content= opens it at its canvas. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $view->label($unit))
@section('head')<link rel="stylesheet" href="/vendor/ahg-theme-b5/js/vendor/mirador/mirador.min.css">@endsection
@section('content')
    <p><a href="{{ route('discover.record', $unit->id) }}">{{ __('Back to :name', ['name' => $view->label($unit)]) }}</a> · <a href="{{ $manifest }}">{{ __('IIIF manifest') }}</a></p>
    <h1>{{ $view->label($unit) }}</h1>
    <div id="mirador" style="position: relative; height: 75vh; min-height: 420px" aria-label="{{ __('Mirador viewer') }}"></div>
    <script src="/vendor/ahg-theme-b5/js/vendor/mirador/mirador.min.js"></script>
    <script>
        Mirador.viewer({
            id: 'mirador',
            windows: [@json(array_filter(['manifestId' => $manifest, 'canvasId' => $canvas]), JSON_UNESCAPED_SLASHES)],
            window: { allowClose: false, allowMaximize: false, sideBarOpen: false },
            workspaceControlPanel: { enabled: false },
        });
    </script>
@endsection
