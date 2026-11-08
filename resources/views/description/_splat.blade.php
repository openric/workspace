{{-- OpenRiC Workspace - a Gaussian splat shown in the page (roadmap phase 21), after Heratio's ahg-3d-model _splat-viewer: the GaussianSplats3D viewer and three.js, both served by the Workspace, joined by an import map. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $src (the file's URL), $alt (what it shows) and $extension (splat, ksplat or ply). --}}
@php($splatId = 'splat-'.substr(md5($src), 0, 12))
<div id="{{ $splatId }}" role="img" aria-label="{{ $alt }}" style="width: 100%; max-width: 48rem; height: min(60vh, 30rem); background: #10373E; position: relative">
    <p id="{{ $splatId }}-wait" style="color: #F2EDE6; padding: 1rem; margin: 0">{{ __('Loading the 3D scene...') }}</p>
</div>
<p class="meta" style="margin-top: .25rem">{{ __('A Gaussian splat: drag to turn the scene, scroll to zoom.') }}</p>
@once
    <script type="importmap">{"imports": {"three": @json(asset('vendor/three/0.160.0/three.module.js'))}}</script>
@endonce
<script type="module">
    import * as GaussianSplats3D from @json(asset('vendor/gaussian-splats-3d/0.4.7/gaussian-splats-3d.module.js'));
    const box = document.getElementById(@json($splatId));
    const viewer = new GaussianSplats3D.Viewer({rootElement: box, cameraUp: [0, -1, 0], initialCameraPosition: [0, 0, 5], initialCameraLookAt: [0, 0, 0], sharedMemoryForWorkers: false});
    // The file's address has no extension, so the format is given: GaussianSplats3D.SceneFormat Splat 0, KSplat 1, Ply 2.
    viewer.addSplatScene(@json($src), {format: @json(['splat' => 0, 'ksplat' => 1, 'ply' => 2][$extension ?? 'splat'] ?? 0), showLoadingUI: false})
        .then(() => { document.getElementById(@json($splatId.'-wait')).remove(); viewer.start(); })
        .catch(() => { document.getElementById(@json($splatId.'-wait')).textContent = @json(__('The scene could not be shown. It can still be downloaded.')); });
</script>
