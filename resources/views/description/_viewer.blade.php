{{-- OpenRiC Workspace - an instantiation's file shown in the viewer that suits it. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Reused from Heratio (ahg-information-object-manage partials/_digital-object-viewer.blade.php and its vendor files, copied under public/vendor):
     images in OpenSeadragon deep zoom, Mirador or a plain image (Heratio's ahg-iiif-viewer.js, unchanged; with no IIIF image server it opens the
     image itself and Mirador builds its manifest in the browser), OBJ and STL in Heratio's three.js viewer, video and audio with Plyr and a
     wavesurfer waveform. PDFs: Heratio shows them in an iframe, which a sandboxed file response cannot fill, so they are drawn with Heratio's
     pdf.js here. GLB models keep model-viewer (description._model).
     Needs $instantiation, $files (the files the reader may receive, keyed by use: master, reference, thumbnail), $title and $fileRoute
     ('files.show' signed in, 'discover.file' public). Shows nothing when no file suits a viewer. --}}
@php
    $master = $files['master'] ?? null;
    $reference = $files['reference'] ?? null;
    $thumbnail = $files['thumbnail'] ?? null;
    $url = fn ($file) => route($fileRoute, $file);
    $extension = fn ($file) => strtolower(pathinfo((string) $file?->original_name, PATHINFO_EXTENSION));
    $browserImage = fn ($file) => $file && in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    $kind = match (true) {
        $reference?->mime_type === \App\Files\FileStore::MODEL || ($master?->mime_type === \App\Files\FileStore::MODEL) => 'glb',
        $master && in_array($extension($master), ['obj', 'stl'], true) => 'mesh',
        $master && $master->mime_type === 'application/pdf' => 'pdf',
        $master && str_starts_with($master->mime_type, 'video/') => 'video',
        $master && str_starts_with($master->mime_type, 'audio/') => 'audio',
        $browserImage($reference) || $browserImage($master) || $browserImage($thumbnail) => 'image',
        default => null,
    };
    $viewerId = 'v-'.$instantiation->id;
@endphp
@if($kind === 'glb')
    @include('description._model', ['src' => $url($reference ?? $master), 'alt' => $title])
@elseif($kind === 'image')
    @php
        $image = $browserImage($reference) ? $reference : ($browserImage($master) ? $master : $thumbnail);
        // As Heratio: the deep-zoom viewer by default; ?viewer=mirador|openseadragon|single picks another.
        $mode = in_array(request()->query('viewer'), ['openseadragon', 'mirador', 'single'], true) ? request()->query('viewer') : 'openseadragon';
    @endphp
    <div class="file-viewer">
        <div class="viewer-modes" role="group" aria-label="{{ __('How to view the image') }}">
            <button type="button" id="btn-osd-{{ $viewerId }}" @class(['active' => $mode === 'openseadragon']) aria-pressed="{{ $mode === 'openseadragon' ? 'true' : 'false' }}">Deep zoom</button>
            <button type="button" id="btn-mirador-{{ $viewerId }}" @class(['active' => $mode === 'mirador']) aria-pressed="{{ $mode === 'mirador' ? 'true' : 'false' }}">Mirador</button>
            <button type="button" id="btn-img-{{ $viewerId }}" @class(['active' => $mode === 'single']) aria-pressed="{{ $mode === 'single' ? 'true' : 'false' }}">Image</button>
            <button type="button" id="btn-fs-{{ $viewerId }}" aria-pressed="false">Full screen</button>
            <a href="{{ $url($image) }}" target="_blank" rel="noopener">Open the image</a>
        </div>
        <div id="osd-{{ $viewerId }}" class="viewer-stage" @if($mode !== 'openseadragon') style="display: none" @endif></div>
        <div id="mirador-{{ $viewerId }}" class="viewer-stage" @if($mode !== 'mirador') style="display: none" @endif></div>
        <div id="img-{{ $viewerId }}" class="viewer-stage viewer-image" @if($mode !== 'single') style="display: none" @endif>
            <img src="{{ $url($image) }}" alt="{{ $title }}" width="{{ $image->width }}" height="{{ $image->height }}">
        </div>
    </div>
    @once
        <script src="{{ asset('vendor/openseadragon/6.0.2/openseadragon.min.js') }}"></script>
        <script src="{{ asset('vendor/openseadragon/6.0.2/openseadragon-filtering.js') }}"></script>
        <script src="{{ asset('vendor/ahg-theme-b5/js/ahg-iiif-viewer.js') }}"></script>
        <script>
            // The viewer marks the chosen mode with the "active" class; say the same to screen readers.
            new MutationObserver(list => list.forEach(m => m.target.setAttribute('aria-pressed', m.target.classList.contains('active') ? 'true' : 'false')))
                .observe(document.body, {subtree: true, attributes: true, attributeFilter: ['class']});
        </script>
    @endonce
    <script>document.addEventListener('DOMContentLoaded', () => initIiifViewer(@json($viewerId), @json($url($image)), @json($title), @json($mode), []));</script>
@elseif($kind === 'pdf')
    <div class="file-viewer pdf-viewer" id="{{ $viewerId }}" data-src="{{ $url($master) }}">
        <div class="viewer-modes" role="group" aria-label="{{ __('Pages') }}">
            <button type="button" data-pdf="prev" aria-pressed="false">Previous page</button>
            <span class="pdf-page" aria-live="polite">Loading...</span>
            <button type="button" data-pdf="next" aria-pressed="false">Next page</button>
            <button type="button" data-pdf="out" aria-pressed="false" aria-label="Zoom out">-</button>
            <button type="button" data-pdf="in" aria-pressed="false" aria-label="Zoom in">+</button>
            <a href="{{ route($fileRoute, [$master, 'download' => 1]) }}">Download the PDF</a>
        </div>
        <div class="viewer-stage pdf-stage"><canvas aria-label="{{ $title }}" role="img"></canvas></div>
    </div>
    @once
        <script src="{{ asset('vendor/pdfjs/3.11.174/pdf.min.js') }}"></script>
        <script>
            // Heratio's pdf.js, drawing one page at a time; the file comes as data, so the response's sandbox does not apply.
            pdfjsLib.GlobalWorkerOptions.workerSrc = @json(asset('vendor/pdfjs/3.11.174/pdf.worker.min.js'));
            document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.pdf-viewer').forEach(async box => {
                const canvas = box.querySelector('canvas'), label = box.querySelector('.pdf-page');
                let doc, page = 1, zoom = 1;
                async function draw() {
                    const p = await doc.getPage(page);
                    // zoom 1 is the page as wide as the box, at most its own size twice over.
                    const fit = Math.min(2, (box.clientWidth - 24) / p.getViewport({scale: 1}).width) * zoom;
                    const viewport = p.getViewport({scale: fit * (window.devicePixelRatio || 1)});
                    canvas.width = viewport.width; canvas.height = viewport.height;
                    canvas.style.width = (viewport.width / (window.devicePixelRatio || 1)) + 'px';
                    await p.render({canvasContext: canvas.getContext('2d'), viewport}).promise;
                    label.textContent = 'Page ' + page + ' of ' + doc.numPages;
                }
                try { doc = await pdfjsLib.getDocument(box.dataset.src).promise; await draw(); }
                catch (e) { label.textContent = 'The PDF could not be shown; download it instead.'; return; }
                box.addEventListener('click', e => {
                    const act = e.target.closest('[data-pdf]')?.dataset.pdf;
                    if (act === 'prev' && page > 1) page--;
                    else if (act === 'next' && page < doc.numPages) page++;
                    else if (act === 'in') zoom = Math.min(zoom * 1.25, 4);
                    else if (act === 'out') zoom = Math.max(zoom / 1.25, .4);
                    else return;
                    draw();
                });
            }));
        </script>
    @endonce
@elseif($kind === 'video' || $kind === 'audio')
    <div class="file-viewer media-viewer">
        @if($kind === 'video')
            <video id="{{ $viewerId }}" controls preload="metadata" @if($thumbnail) poster="{{ $url($thumbnail) }}" @endif style="width: 100%; max-height: 500px; background: #000">
                <source src="{{ $url($master) }}" type="{{ $master->mime_type }}">
            </video>
        @else
            <div class="waveform" id="wave-{{ $viewerId }}" aria-hidden="true"></div>
            <audio id="{{ $viewerId }}" controls preload="metadata" style="width: 100%"><source src="{{ $url($master) }}" type="{{ $master->mime_type }}"></audio>
        @endif
    </div>
    @once
        <link rel="stylesheet" href="{{ asset('vendor/plyr/plyr.css') }}">
        <script src="{{ asset('vendor/plyr/plyr.min.js') }}"></script>
        <script src="{{ asset('vendor/wavesurfer/wavesurfer.min.js') }}"></script>
        <script>
            // As Heratio's layout: Plyr wraps each player; an audio file also gets a WaveSurfer waveform that follows it.
            document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.media-viewer video, .media-viewer audio').forEach(el => {
                const player = window.Plyr ? new Plyr(el, {iconUrl: @json(asset('vendor/plyr/plyr.svg')), loadSprite: true}) : null;
                const wave = el.tagName === 'AUDIO' && window.WaveSurfer && document.getElementById('wave-' + el.id);
                if (wave) {
                    const ws = WaveSurfer.create({container: wave, height: 64, waveColor: '#94a3b8', progressColor: '#1f5fa8', cursorColor: '#0f3e75', media: el});
                    ws.on('error', () => { wave.hidden = true; });
                }
            }));
        </script>
    @endonce
@elseif($kind === 'mesh')
    <div class="file-viewer">
        <div id="{{ $viewerId }}-container" class="viewer-stage mesh-stage">
            <canvas id="{{ $viewerId }}-canvas" style="width: 100%; height: 100%" aria-label="{{ $title }}" role="img"></canvas>
            <p id="{{ $viewerId }}-loading" class="mesh-loading">Loading the 3D model...</p>
        </div>
        <p class="meta">Drag to turn the model, scroll to zoom.</p>
    </div>
    @once
        <script type="importmap">{"imports": {"three": @json(asset('vendor/three/0.160.0/three.module.js')), "three/addons/": @json(asset('vendor/three/0.160.0/addons').'/')}}</script>
    @endonce
    <script type="module">
        // Heratio's three.js viewer for OBJ and STL, as it is.
        import * as THREE from 'three';
        import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
        import { {{ $extension($master) === 'obj' ? 'OBJLoader as Loader' : 'STLLoader as Loader' }} } from 'three/addons/loaders/{{ $extension($master) === 'obj' ? 'OBJLoader' : 'STLLoader' }}.js';
        const container = document.getElementById(@json($viewerId.'-container')), canvas = document.getElementById(@json($viewerId.'-canvas')), loading = document.getElementById(@json($viewerId.'-loading'));
        const scene = new THREE.Scene(); scene.background = new THREE.Color(0x1a1a2e);
        const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 1000); camera.position.set(0, 1, 3);
        const renderer = new THREE.WebGLRenderer({canvas, antialias: true}); renderer.setSize(container.clientWidth, container.clientHeight); renderer.setPixelRatio(window.devicePixelRatio);
        const controls = new OrbitControls(camera, canvas); controls.enableDamping = true;
        scene.add(new THREE.AmbientLight(0xffffff, 0.6)); const light = new THREE.DirectionalLight(0xffffff, 0.8); light.position.set(5, 10, 7); scene.add(light); scene.add(new THREE.HemisphereLight(0xffffff, 0x444444, 0.4));
        new Loader().load(@json($url($master)), object => {
            if (object.isBufferGeometry) object = new THREE.Mesh(object, new THREE.MeshPhongMaterial({color: 0xcccccc}));
            const box = new THREE.Box3().setFromObject(object), center = box.getCenter(new THREE.Vector3()), size = box.getSize(new THREE.Vector3());
            const scale = 2 / Math.max(size.x, size.y, size.z); object.scale.multiplyScalar(scale); object.position.sub(center.multiplyScalar(scale));
            scene.add(object); loading.hidden = true; controls.update();
        }, xhr => { if (xhr.total) loading.textContent = Math.round(xhr.loaded / xhr.total * 100) + '%'; }, () => { loading.textContent = 'The 3D model could not be shown; download it instead.'; });
        (function animate() { requestAnimationFrame(animate); controls.update(); renderer.render(scene, camera); })();
        window.addEventListener('resize', () => { camera.aspect = container.clientWidth / container.clientHeight; camera.updateProjectionMatrix(); renderer.setSize(container.clientWidth, container.clientHeight); });
    </script>
@endif
@once
    <style>
        .file-viewer { margin: .5rem 0 1rem; }
        .viewer-modes { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; margin-bottom: .5rem; font-size: .9rem; }
        .viewer-modes button { margin: 0; padding: .3rem .75rem; font-size: .85rem; box-shadow: none; }
        .viewer-modes button:not(.active) { background: var(--bg); color: var(--ink); border-color: var(--line); }
        .viewer-modes .pdf-page { color: var(--muted-2); }
        .viewer-stage { position: relative; width: 100%; height: min(70vh, 34rem); background: #1a1a1a; border-radius: var(--radius); overflow: hidden; }
        .viewer-image { display: flex; align-items: center; justify-content: center; background: var(--surface); }
        .viewer-image img { max-height: 100%; max-width: 100%; width: auto; height: auto; }
        .pdf-stage { height: auto; max-height: 80vh; overflow: auto; background: #525659; text-align: center; padding: .5rem 0; }
        .pdf-stage canvas { background: #fff; box-shadow: 0 2px 8px rgba(0, 0, 0, .4); }
        .mesh-stage { height: 400px; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); }
        .mesh-loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #fff; margin: 0; }
        .waveform { margin-bottom: .5rem; }
    </style>
@endonce
