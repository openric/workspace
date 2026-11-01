{{-- OpenRiC Workspace - the annotation studio of an entity (Heratio's annotationStudio and evidence viewer; roadmap phase 19d): its images to mark a region on, the annotations one may read, their tags, and the form. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Annotate').': '.\App\Relations\EntityKinds::label($entity, \App\Support\Vocab::languages()))
@section('content')
    @php($lang = \App\Support\Vocab::languages())
    @php($a = $editing)
    @php($t = $a?->targets->first())
    <p><a href="{{ \App\Relations\EntityKinds::route($entity) }}">{{ \App\Relations\EntityKinds::label($entity, $lang) }}</a> · <a href="{{ route('research.annotations.index') }}">{{ __('My annotations') }}</a> · <a href="{{ route('research.studio.page', [$kind, $entity->getKey()]) }}">{{ __('As a W3C annotation page') }}</a></p>
    <h1>{{ __('Annotate') }}: {{ \App\Relations\EntityKinds::label($entity, $lang) }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('research')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($tags)<p>{{ __('Tags') }}: @foreach($tags as $tag)<a href="{{ route('research.annotations.index', ['q' => $tag]) }}">{{ $tag }}</a>@if(! $loop->last), @endif @endforeach</p>@endif
    @if($canvases)
        <section aria-labelledby="st-images">
            <h2 id="st-images">{{ __('Images') }}</h2>
            <p class="meta">{{ __('Choose an image, then drag across it to mark the region the annotation is about.') }}</p>
            <select id="st-canvas" aria-label="{{ __('Image') }}">@foreach($canvases as $c)<option value="{{ $c['id'] }}" data-url="{{ $c['url'] }}" data-width="{{ $c['width'] }}" data-height="{{ $c['height'] }}" @selected(old('canvas_id', $a?->canvas_id) === $c['id'])>{{ $c['label'] ?: __('Image :n', ['n' => $loop->iteration]) }}</option>@endforeach</select>
            <div id="st-stage" style="position: relative; display: inline-block; max-width: 100%; margin-top: .4rem; cursor: crosshair; user-select: none">
                <img id="st-image" src="{{ $canvases[0]['url'] }}" alt="" style="max-width: 100%; display: block" draggable="false">
                <div id="st-box" hidden style="position: absolute; border: 2px solid #E1B34F; background: rgba(225,179,79,.15); pointer-events: none"></div>
            </div>
        </section>
    @endif
    <section aria-labelledby="st-form">
        <h2 id="st-form">{{ $a ? __('Change the annotation') : __('New annotation') }}</h2>
        <form method="post" action="{{ route('research.studio.save', [$kind, $entity->getKey()]) }}">@csrf
            @if($a)<input type="hidden" name="annotation_id" value="{{ $a->id }}">@endif
            <label for="an-type">{{ __('Kind') }}</label> <select id="an-type" name="annotation_type">@foreach($types as $c)<option value="{{ $c->notation }}" @selected(old('annotation_type', $a?->annotationType->notation ?? 'note') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
            <label for="an-vis">{{ __('Who may read it') }}</label> <select id="an-vis" name="visibility">@foreach($visibilities as $c)<option value="{{ $c->notation }}" @selected(old('visibility', $a?->visibility->notation ?? 'private') === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select><br>
            <label for="an-title">{{ __('Title') }}</label> <input id="an-title" name="title" size="50" value="{{ old('title', $a?->title) }}"><br>
            <label for="an-content">{{ __('Text') }}</label><br><textarea id="an-content" name="content" rows="5" cols="80">{{ old('content', $a?->content) }}</textarea><br>
            <label for="an-tags">{{ __('Tags') }}</label> <input id="an-tags" name="tags" size="40" value="{{ old('tags', $a?->tags) }}" aria-describedby="an-tags-hint"> <span id="an-tags-hint" class="meta">{{ __('Separate them with commas.') }}</span><br>
            @if($collections->isNotEmpty())<label for="an-coll">{{ __('Collection') }}</label> <select id="an-coll" name="collection_id"><option value="">{{ __('None') }}</option>@foreach($collections as $c)<option value="{{ $c->id }}" @selected(old('collection_id', $a?->collection_id) === $c->id)>{{ $c->name }}</option>@endforeach</select><br>@endif
            <fieldset><legend>{{ __('Where in it') }}</legend>
                <input type="hidden" id="an-canvas" name="canvas_id" value="{{ old('canvas_id', $a?->canvas_id) }}">
                <label for="an-sel">{{ __('Selector') }}</label> <select id="an-sel" name="selector_type"><option value="">{{ __('The whole of it') }}</option>@foreach($selectors as $c)<option value="{{ $c->notation }}" @selected(old('selector_type', $t?->selectorType->notation) === $c->notation)>{{ $c->label($lang) }}</option>@endforeach</select>
                <label for="an-selv">{{ __('Value') }}</label> <input id="an-selv" name="selector_value" size="40" value="{{ old('selector_value', $t ? ($t->selector['value'] ?? (isset($t->selector['x']) ? $t->selector['x'].','.$t->selector['y'] : (isset($t->selector['startSelector']) ? $t->selector['startSelector']['start'].','.$t->selector['endSelector']['start'] : ''))) : '') }}" aria-describedby="an-selv-hint"><br>
                <span id="an-selv-hint" class="meta">{{ __('A region: xywh=x,y,width,height. A point: x,y. A range of text: from,to (characters). A time: t=from,to (seconds). A shape: SVG.') }}</span><br>
                <label for="an-exact">{{ __('Quoted words') }}</label> <input id="an-exact" name="exact" size="40" value="{{ old('exact', $t?->selector['exact'] ?? '') }}">
                <label for="an-prefix">{{ __('Before them') }}</label> <input id="an-prefix" name="prefix" size="15" value="{{ old('prefix', $t?->selector['prefix'] ?? '') }}">
                <label for="an-suffix">{{ __('After them') }}</label> <input id="an-suffix" name="suffix" size="15" value="{{ old('suffix', $t?->selector['suffix'] ?? '') }}">
                @if($t)<br><label><input type="checkbox" name="clear_selector" value="1"> {{ __('Point to the whole of it again') }}</label>@endif
            </fieldset>
            <button type="submit">{{ __('Save') }}</button> @if($a)<a href="{{ route('research.studio', [$kind, $entity->getKey()]) }}">{{ __('Cancel') }}</a>@endif
        </form>
    </section>
    <section aria-labelledby="st-list">
        <h2 id="st-list">{{ __('Annotations') }}</h2>
        @forelse($annotations as $n)
            <article style="border-top: 1px solid var(--line); padding: .3rem 0">
                <p><strong>{{ $n->annotationType->label($lang) }}</strong>@if($n->title): {{ $n->title }}@endif <span class="meta">{{ $n->user?->name }} · {{ $n->visibility->label($lang) }} · {{ $n->created_at->timezone(config('workspace.timezone'))->format('j M Y H:i') }}@if($n->targets->first()) · {{ $n->targets->first()->selectorType->label($lang) }}@endif</span></p>
                @if($n->content)<p style="white-space: pre-wrap">{{ $n->content }}</p>@endif
                @if($n->tags)<p class="meta">{{ __('Tags') }}: {{ $n->tags }}</p>@endif
                @include('research._comments', ['subject' => $n, 'kind' => 'annotation'])
                @if($n->user_id === auth()->id())<p><a href="{{ route('research.studio', [$kind, $entity->getKey(), 'edit' => $n->id]) }}">{{ __('Change') }}</a> @include('research._project-select', ['thing' => $n, 'kind' => 'annotation'])
                    <form method="post" action="{{ route('research.annotations.delete', $n) }}" style="display: inline">@csrf<button type="submit">{{ __('Delete') }}</button></form></p>@endif
            </article>
        @empty<p>{{ __('No annotation yet.') }}</p>@endforelse
    </section>
    @if($canvases)
    <script>
    (function () {
        var select = document.getElementById('st-canvas'), img = document.getElementById('st-image'), stage = document.getElementById('st-stage'), box = document.getElementById('st-box');
        var canvasField = document.getElementById('an-canvas'), selType = document.getElementById('an-sel'), selValue = document.getElementById('an-selv'), start = null;
        function chosen() { return select.options[select.selectedIndex]; }
        function show() { img.src = chosen().dataset.url; box.hidden = true; }
        select.addEventListener('change', show);
        if (canvasField.value) { show(); }
        function at(e) { var r = img.getBoundingClientRect(); return { x: Math.max(0, Math.min(r.width, e.clientX - r.left)), y: Math.max(0, Math.min(r.height, e.clientY - r.top)), r: r }; }
        stage.addEventListener('pointerdown', function (e) { start = at(e); stage.setPointerCapture(e.pointerId); });
        stage.addEventListener('pointermove', function (e) {
            if (!start) { return; }
            var p = at(e);
            box.hidden = false; box.style.left = Math.min(start.x, p.x) + 'px'; box.style.top = Math.min(start.y, p.y) + 'px';
            box.style.width = Math.abs(p.x - start.x) + 'px'; box.style.height = Math.abs(p.y - start.y) + 'px';
        });
        stage.addEventListener('pointerup', function (e) {
            if (!start) { return; }
            var p = at(e), o = chosen(), w = Number(o.dataset.width) || img.naturalWidth, h = Number(o.dataset.height) || img.naturalHeight, sx = w / p.r.width, sy = h / p.r.height;
            var x = Math.round(Math.min(start.x, p.x) * sx), y = Math.round(Math.min(start.y, p.y) * sy), bw = Math.round(Math.abs(p.x - start.x) * sx), bh = Math.round(Math.abs(p.y - start.y) * sy);
            start = null;
            canvasField.value = o.value;
            if (bw < 3 || bh < 3) { selType.value = 'point'; selValue.value = x + ',' + y; } else { selType.value = 'fragment'; selValue.value = 'xywh=' + x + ',' + y + ',' + bw + ',' + bh; }
        });
    })();
    </script>
    @endif
@endsection
