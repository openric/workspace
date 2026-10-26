{{-- OpenRiC Workspace - a glTF binary (GLB) 3D model shown in the page, with model-viewer served by the Workspace. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $src (the file's URL) and $alt (what the model shows). --}}
<model-viewer src="{{ $src }}" alt="{{ $alt }}" camera-controls touch-action="pan-y" shadow-intensity="1" loading="lazy"
    style="display: block; width: 100%; max-width: 40rem; height: min(60vh, 28rem); background: #1b2328; border: 1px solid var(--line)">
    <p slot="poster" style="color: #e4e7eb; padding: 1rem; margin: 0">{{ __('Loading the 3D model...') }}</p>
</model-viewer>
<p class="meta" style="margin-top: .25rem">{{ __('Drag to turn the model, pinch or scroll to zoom. On a keyboard, select the model, then use the arrow keys.') }}</p>
@once
    {{-- The decoders for compressed models come from the Workspace too, not from gstatic.com; set before model-viewer loads. --}}
    <script>self.ModelViewerElement = {dracoDecoderLocation: @json(asset('vendor/model-viewer/draco').'/'), ktx2TranscoderLocation: @json(asset('vendor/model-viewer/basis').'/')};</script>
    <script type="module" src="{{ asset('vendor/model-viewer/model-viewer.min.js') }}"></script>
@endonce
