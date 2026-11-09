{{-- OpenRiC Workspace - a 3D model shown in the page with model-viewer, served by the Workspace (roadmap phase 21, after Heratio's ahg-3d-model _model3d-viewer): shown as its institution and the model set it, with its hotspots in the reader's language, a turntable video where one was made, and toolbars for animations, saved views, a link to the exact view, measuring and a cross-section. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $src (the file's URL) and $alt (what the model shows); with $instantiation, its settings, hotspots and toolbars; $embed shows the model alone. --}}
@php
    $instantiation = $instantiation ?? null;
    $embed = $embed ?? false;
    $settings = $instantiation ? app(\App\Files\ModelViews::class)->settings($instantiation) : \App\Files\ModelViews::DEFAULTS;
    $reader = auth()->user();
    // In the public catalogue everyone sees what is published, staff too.
    $mayEdit = ! ($public ?? false) && ! $embed && $instantiation && $reader && $reader->institution_id === $instantiation->institution_id && $reader->may('update', 'description', $instantiation->record);
    $languages = array_values(array_unique([app()->getLocale(), 'en']));
    $hotspots = $hotspots ?? ($instantiation ? app(\App\Files\ModelViews::class)->hotspots($instantiation, $mayEdit, $languages) : collect());
    $viewerId = 'm3d-'.($instantiation?->id ?? substr(md5($src), 0, 12));
    $turntable = $instantiation?->files?->first(fn ($f) => $f->use?->notation === 'access' && $f->mime_type === 'video/mp4');
    $texts = ['Play' => __('Play'), 'Pause' => __('Pause'), 'for everyone' => __('for everyone'), 'Delete' => __('Delete'), 'No saved views yet' => __('No saved views yet'),
        'Delete this saved view?' => __('Delete this saved view?'), 'Name this view' => __('Name this view'), 'Link copied' => __('Link copied'),
        'Link to this view' => __('Link to this view'), 'Copy this link' => __('Copy this link')];
@endphp
<div class="model3d" @if($instantiation && \App\Support\Modules::on('3d')) data-model="{{ $instantiation->id }}" data-bookmarks="{{ route('models.bookmarks', $instantiation) }}" @endif data-text="{{ json_encode($texts) }}">
    <model-viewer id="{{ $viewerId }}" src="{{ $src }}" alt="{{ $alt }}" camera-controls touch-action="pan-y" loading="lazy"
        @if($settings['auto_rotate']) auto-rotate rotation-per-second="{{ (int) $settings['rotation_speed'] }}deg" @endif
        camera-orbit="{{ $settings['camera_orbit'] }}" @if($settings['min_camera_orbit']) min-camera-orbit="{{ $settings['min_camera_orbit'] }}" @endif
        @if($settings['max_camera_orbit']) max-camera-orbit="{{ $settings['max_camera_orbit'] }}" @endif field-of-view="{{ $settings['field_of_view'] }}"
        exposure="{{ $settings['exposure'] }}" shadow-intensity="{{ $settings['shadow_intensity'] }}" shadow-softness="{{ $settings['shadow_softness'] }}"
        @if($settings['neutral_lighting']) environment-image="neutral" @endif
        @if($settings['ar_enabled']) ar ar-modes="webxr scene-viewer" ar-placement="{{ $settings['ar_placement'] }}" ar-scale="{{ $settings['ar_fixed_scale'] ? 'fixed' : 'auto' }}" @endif
        data-measure-prompt-first="{{ __('Click the first point on the model') }}" data-measure-prompt-second="{{ __('Click the second point') }}"
        data-measure-miss="{{ __('No surface of the model there; try again') }}" data-measure-result="{{ __('Distance:') }}"
        style="display: block; width: 100%; max-width: {{ $embed ? 'none' : '48rem' }}; height: {{ $embed ? '100vh' : 'min(60vh, 30rem)' }}; background: {{ $settings['background_color'] }}; border: 1px solid var(--line, #ccc)">
        @foreach($hotspots as $i => ['hotspot' => $h, 'text' => $t])
            <button class="model3d-hotspot" slot="hotspot-{{ $i }}" data-position="{{ $h->dataPosition() }}" data-normal="{{ $h->dataNormal() }}"
                style="border: 2px solid #fff; border-radius: 50%; width: 1.4rem; height: 1.4rem; background: {{ $h->color }}; color: #fff; font-size: .75rem; cursor: pointer"
                aria-describedby="{{ $viewerId }}-h{{ $i }}">{{ $i + 1 }}</button>
        @endforeach
        <p slot="poster" style="color: #262626; padding: 1rem; margin: 0">{{ __('Loading the 3D model...') }}</p>
        @if($settings['ar_enabled'])<button slot="ar-button" type="button" style="position: absolute; bottom: 1rem; left: 1rem">{{ __('View in your room') }}</button>@endif
    </model-viewer>
    @unless($embed)
        <p class="meta" style="margin-top: .25rem">{{ __('Drag to turn the model, pinch or scroll to zoom. On a keyboard, select the model, then use the arrow keys.') }}</p>
    @endunless
    @if($hotspots->isNotEmpty())
        <ol class="model3d-hotspots">
            @foreach($hotspots as $i => ['hotspot' => $h, 'text' => $t])
                <li id="{{ $viewerId }}-h{{ $i }}" lang="{{ $t->language }}"><strong>{{ $t->title }}</strong> <small>({{ $h->type->label(\App\Support\Vocab::languages()) }})</small>
                    @if($t->description)<br>{{ $t->description }}@endif
                    @if($h->link_url) <a href="{{ $h->link_url }}" rel="noopener">{{ __('More') }}</a>@endif</li>
            @endforeach
        </ol>
    @endif
    @if($instantiation && ! $embed)
        <div class="model3d-anim" hidden role="toolbar" aria-label="{{ __('Animation') }}">
            <button type="button" class="model3d-anim-play">{{ __('Play') }}</button>
            <label class="visually-hidden" for="{{ $viewerId }}-anim">{{ __('Animation') }}</label><select id="{{ $viewerId }}-anim" class="model3d-anim-name"></select>
            <label class="visually-hidden" for="{{ $viewerId }}-scrub">{{ __('Where in the animation') }}</label><input id="{{ $viewerId }}-scrub" type="range" class="model3d-anim-scrub" min="0" max="1000" value="0">
            <small class="model3d-anim-time">00:00 / 00:00</small>
        </div>
        <details class="model3d-tools"><summary>{{ __('Views, measuring and cross-section') }}</summary>
            <p><strong>{{ __('Saved views') }}</strong></p>
            <ul class="model3d-views"><li>{{ __('Loading...') }}</li></ul>
            @auth
                <button type="button" class="model3d-save">{{ __('Save this view') }}</button>
                @if($mayEdit)<label><input type="checkbox" class="model3d-public"> {{ __('for everyone') }}</label>@endif
            @endauth
            <button type="button" class="model3d-share">{{ __('Link to this view') }}</button>
            <div class="ahg-3d-measure-toolbar" data-target="{{ $viewerId }}" data-measure-units="m">
                <p><button type="button" class="ahg-3d-measure-toggle" aria-pressed="false">{{ __('Measure') }}</button>
                    <button type="button" class="ahg-3d-measure-clear">{{ __('Clear') }}</button>
                    <small class="ahg-3d-measure-status" aria-live="polite"></small></p>
                <p class="meta">{{ __('Click two points on the model; the distance is in the model\'s units, metres for a glTF.') }}</p>
            </div>
            <div class="ahg-3d-cross-toolbar" data-target="{{ $viewerId }}">
                <p><button type="button" class="ahg-3d-cross-toggle" aria-pressed="false">{{ __('Cross-section') }}</button></p>
                <div class="ahg-3d-cross-controls d-none">
                    <fieldset><legend>{{ __('Along') }}</legend>
                        @foreach(['x', 'y', 'z'] as $axis)<label><input type="radio" name="ahg-3d-cross-axis-{{ $viewerId }}" value="{{ $axis }}" @checked($axis === 'x')> {{ strtoupper($axis) }}</label> @endforeach
                    </fieldset>
                    <label for="{{ $viewerId }}-slice">{{ __('Where the slice is') }}</label>
                    <input id="{{ $viewerId }}-slice" type="range" class="ahg-3d-cross-slider" min="0" max="100" value="50">
                    <small class="ahg-3d-cross-value" aria-live="polite"></small>
                </div>
            </div>
        </details>
        @if($turntable)<p><a href="{{ route($fileRoute ?? 'files.show', $turntable) }}">{{ __('Turntable video') }}</a></p>@endif
        @if($mayEdit && \App\Support\Modules::on('3d'))<p><a href="{{ route('models.edit', $instantiation) }}">{{ __('How the model is shown, and its hotspots') }}</a></p>@endif
    @endif
</div>
@once
    <style>.model3d .d-none{display:none!important}.model3d .d-flex{display:flex!important}.model3d-hotspots{margin:.5rem 0}.model3d .active{outline:2px solid #E1B34F}</style>
    {{-- The decoders for compressed models come from the Workspace too, not from gstatic.com; set before model-viewer loads. --}}
    <script>self.ModelViewerElement = {dracoDecoderLocation: @json(asset('vendor/model-viewer/draco').'/'), ktx2TranscoderLocation: @json(asset('vendor/model-viewer/basis').'/')};</script>
    <script type="module" src="{{ asset('vendor/model-viewer/model-viewer.min.js') }}"></script>
    <script src="{{ asset('vendor/openric/model3d/tools.js') }}" defer></script>
    <script src="{{ asset('vendor/openric/model3d/measure.js') }}" defer></script>
    <script src="{{ asset('vendor/openric/model3d/cross-section.js') }}" defer></script>
@endonce
