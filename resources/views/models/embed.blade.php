<!doctype html>
{{-- OpenRiC Workspace - a published 3D model alone, to embed in another page (roadmap phase 21, after Heratio's ahg-3d-model embed view). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $instantiation->described('instantiation-title', [app()->getLocale(), 'en']) ?? __('3D model') }} - {{ $institution->name }}</title>
    <style>html,body{margin:0;background:#fff;font-family:Arial,Helvetica,sans-serif}.model3d-hotspots{position:absolute;bottom:0;left:0;right:0;margin:0;padding:.5rem 1.5rem;background:rgba(255,255,255,.9);max-height:30vh;overflow:auto}</style>
</head>
<body>
    @include('description._model', ['src' => route('discover.file', $model), 'alt' => $instantiation->described('instantiation-title', [app()->getLocale(), 'en']) ?? __('3D model'),
        'instantiation' => $instantiation, 'hotspots' => $hotspots, 'embed' => true, 'public' => true, 'fileRoute' => 'discover.file'])
    <p style="position:absolute;top:.25rem;right:.5rem;margin:0;font-size:.8rem"><a href="{{ route('discover.record', $instantiation->record_resource_id) }}" target="_top">{{ $institution->name }}</a></p>
</body>
</html>
