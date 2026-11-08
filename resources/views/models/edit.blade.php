{{-- OpenRiC Workspace - a 3D model's page (roadmap phase 21), after Heratio's ahg-3d-model edit view: how it is shown, what it is made of, its hotspots placed by clicking on it and written in each language, and its turntable video. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('3D model'))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($title = $instantiation->described('instantiation-title', [app()->getLocale(), 'en']) ?? __('3D model'))
    <p><a href="{{ route('instantiations.show', $instantiation) }}">{{ __('Back to :name', ['name' => $title]) }}</a></p>
    <h1>{{ __('3D model: :name', ['name' => $title]) }} @include('description._ric', ['term' => 'rico:Instantiation'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    @include('description._model', ['src' => route('files.show', $model), 'alt' => $title, 'instantiation' => $instantiation, 'fileRoute' => 'files.show'])

    @if($properties)
        <h2>{{ __('What it is made of') }}</h2>
        <dl>
            @foreach(['format' => __('Format'), 'format_version' => __('Format version'), 'vertex_count' => __('Vertices'), 'face_count' => __('Faces'), 'texture_count' => __('Textures'),
                'animation_count' => __('Animations'), 'has_materials' => __('Materials'), 'has_rig' => __('A rig'), 'compression' => __('Compression'), 'bounding_box' => __('Dimensions (model units)'), 'pbr_maps' => __('Material maps')] as $key => $label)
                @isset($properties[$key])<dt>{{ $label }}</dt><dd>{{ is_bool($properties[$key]) || in_array($key, ['has_materials', 'has_rig'], true) ? ($properties[$key] ? __('Yes') : __('No')) : (is_numeric($properties[$key]) ? number_format((float) $properties[$key]) : $properties[$key]) }}</dd>@endisset
            @endforeach
        </dl>
        <p class="meta">{{ __('Read from the master when it was received, and kept with its preservation metadata as its significant properties.') }}</p>
    @endif

    <h2>{{ __('How it is shown') }}</h2>
    <p>{{ __('Left empty, each setting is as the institution\'s defaults say.') }} @if(auth()->user()->hasPermission(\App\Support\Vocab::permission('manage-institution')))<a href="{{ route('models.defaults') }}">{{ __('The institution\'s defaults') }}</a>@endif</p>
    @include('models._settings', ['action' => route('models.settings', $instantiation), 'own' => $own, 'effective' => $settings, 'placements' => $placements])

    <h2>{{ __('Hotspots') }}</h2>
    <p>{{ __('A labelled point on the model. Each is a draft until the record is published, and is shown in the reader\'s language where it is written in it. In IIIF it is a Web Annotation on the model; RiC-O has no term for it.') }}</p>
    @forelse($hotspots as $h)
        <details>
            <summary>{{ $loop->iteration }}. {{ $h->texts->first()?->title }} <small>({{ $h->type->label($lang) }}{{ $h->texts->map(fn ($t) => ' · '.$t->language.' '.$t->state->label($lang))->join('') }})</small></summary>
            @foreach([...$h->texts->pluck('language')->all(), '__new'] as $language)
                @php($t = $h->texts->firstWhere('language', $language))
                <form method="post" action="{{ route('models.hotspots.update', [$instantiation, $h]) }}">@csrf
                    <fieldset><legend>{{ $t ? __('In :language', ['language' => $language]) : __('In another language') }}</legend>
                        <label for="hl-{{ $h->id }}-{{ $loop->index }}">{{ __('Language (a BCP 47 tag)') }}</label>
                        <input id="hl-{{ $h->id }}-{{ $loop->index }}" name="language" value="{{ $t?->language }}" required maxlength="35" @if($t) readonly @endif>
                        <label for="ht-{{ $h->id }}-{{ $loop->index }}">{{ __('Title') }}</label><input id="ht-{{ $h->id }}-{{ $loop->index }}" name="title" value="{{ $t?->title }}" required maxlength="255">
                        <label for="hd-{{ $h->id }}-{{ $loop->index }}">{{ __('Description') }}</label><textarea id="hd-{{ $h->id }}-{{ $loop->index }}" name="description" maxlength="5000">{{ $t?->description }}</textarea>
                        @include('models._hotspot-fields', ['prefix' => 'h-'.$h->id.'-'.$loop->index, 'hotspot' => $h])
                        <button type="submit">{{ __('Save') }}</button>
                    </fieldset>
                </form>
            @endforeach
            <form method="post" action="{{ route('models.hotspots.destroy', [$instantiation, $h]) }}" onsubmit="return confirm('{{ __('Delete this hotspot?') }}')">@csrf<button type="submit">{{ __('Delete the hotspot') }}</button></form>
        </details>
    @empty
        <p>{{ __('None yet.') }}</p>
    @endforelse

    <h3>{{ __('Place a hotspot') }}</h3>
    <form method="post" action="{{ route('models.hotspots.store', $instantiation) }}" id="place-hotspot">@csrf
        <p><button type="button" id="pick-point" aria-pressed="false">{{ __('Click on the model to choose the point') }}</button> <span id="picked" role="status"></span></p>
        @foreach(['position_x', 'position_y', 'position_z', 'normal_x', 'normal_y', 'normal_z'] as $c)<input type="hidden" name="{{ $c }}" id="new-{{ $c }}" value="{{ old($c) }}">@endforeach
        <label for="new-language">{{ __('Language (a BCP 47 tag)') }}</label><input id="new-language" name="language" value="{{ old('language', app()->getLocale()) }}" required maxlength="35">
        <label for="new-title">{{ __('Title') }}</label><input id="new-title" name="title" value="{{ old('title') }}" required maxlength="255">
        <label for="new-description">{{ __('Description') }}</label><textarea id="new-description" name="description" maxlength="5000">{{ old('description') }}</textarea>
        @include('models._hotspot-fields', ['prefix' => 'new', 'hotspot' => null])
        <button type="submit">{{ __('Place the hotspot') }}</button>
    </form>
    <script>
    (function () {
        var viewer = document.querySelector('.model3d model-viewer'), btn = document.getElementById('pick-point'), status = document.getElementById('picked');
        if (!viewer || !btn) return;
        var on = false;
        btn.addEventListener('click', function () { on = !on; btn.setAttribute('aria-pressed', on); status.textContent = on ? @json(__('Click a point on the model.')) : ''; });
        viewer.addEventListener('click', function (e) {
            if (!on) return;
            var r = viewer.getBoundingClientRect(), hit = viewer.positionAndNormalFromPoint(e.clientX - r.left, e.clientY - r.top);
            if (!hit) { status.textContent = @json(__('No surface of the model there; try again.')); return; }
            ['x', 'y', 'z'].forEach(function (a) { document.getElementById('new-position_' + a).value = hit.position[a].toFixed(6); document.getElementById('new-normal_' + a).value = hit.normal[a].toFixed(6); });
            on = false; btn.setAttribute('aria-pressed', 'false');
            status.textContent = @json(__('Point chosen:')) + ' ' + hit.position.toString();
        });
    })();
    </script>

    <h2>{{ __('Turntable video') }}</h2>
    <p>{{ __('A video all round the model, rendered by Blender and kept as its access copy, for a page or a reader that cannot show a 3D model.') }}</p>
    @if($turntable)<p><a href="{{ route('files.show', $turntable) }}">{{ $turntable->original_name }}</a></p>@endif
    <form method="post" action="{{ route('models.turntable', $instantiation) }}">@csrf<button type="submit">{{ $turntable ? __('Make it again') : __('Make a turntable video') }}</button></form>
@endsection
