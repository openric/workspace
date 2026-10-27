{{-- OpenRiC Workspace - images and their alternative text (roadmap phase 5; after Heratio's ahg-core alt-text worklist, PSIS ahgAccessibilityPlugin). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Alternative text'))
@section('content')
    <h1>{{ __('Alternative text') }} @include('description._ric', ['term' => 'openricx:alternativeText'])</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <p>{{ __('Say in words what each image shows, for those who cannot see it (WCAG 2.1, 1.1.1). The text is shown with the image in the catalogue and the viewer; it is a draft until it is published.') }}</p>
    <p><strong>{{ __(':with of :total images have alternative text in :lang (:pct%).', ['with' => $coverage['with'], 'total' => $coverage['total'], 'lang' => $lang, 'pct' => $coverage['pct']]) }}</strong></p>
    <form method="get" action="{{ route('alt-text.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
        <div><label for="language" style="margin-top: 0">{{ __('Language') }}</label>
            <input id="language" name="language" value="{{ $lang }}" size="8" aria-describedby="lang-hint"> <small id="lang-hint">{{ __('A BCP 47 tag, such as en or af') }}</small></div>
        <div><label for="show" style="margin-top: 0">{{ __('Show') }}</label>
            <select id="show" name="show" style="font: inherit; padding: .5rem">
                <option value="missing" @selected($show === 'missing')>{{ __('Images without it') }}</option>
                <option value="all" @selected($show === 'all')>{{ __('All images') }}</option>
            </select></div>
        <button type="submit" style="margin-top: 0">{{ __('Show') }}</button>
    </form>
    @if($worklist->isEmpty())
        <p>{{ $show === 'missing' && $coverage['total'] > 0 ? __('Every image has alternative text in :lang.', ['lang' => $lang]) : __('No images.') }}</p>
    @else
        <ul>
            @foreach($worklist as $instantiation)
                @php
                    $user = auth()->user();
                    $unit = $instantiation->record;
                    $thumbnail = $instantiation->file('thumbnail');
                    $master = $instantiation->file('master');
                    $caption = collect(['XMP-dc:Description', 'IPTC:Caption-Abstract'])->map(fn ($tag) => $master?->embedded_metadata[$tag] ?? null)->filter()->first();
                    $current = $instantiation->descriptionValues->first(fn ($v) => $v->element->notation === 'instantiation-alt-text' && $v->language === $lang);
                    $mayUpdate = $user->may('update', 'description', $unit);
                @endphp
                <li style="display: flex; gap: 1rem; flex-wrap: wrap">
                    @if($thumbnail && app(\App\Description\InstantiationService::class)->deliverable($user, $thumbnail))
                        <img src="{{ route('files.show', $thumbnail) }}" alt="" width="{{ $thumbnail->width }}" height="{{ $thumbnail->height }}" loading="lazy" style="max-width: 160px; height: auto; border: 1px solid var(--line)">
                    @endif
                    <div style="flex: 1 1 18rem; min-width: 0">
                        <a href="{{ route('instantiations.show', $instantiation) }}"><strong>{{ $instantiation->described('instantiation-title', [app()->getLocale(), 'en']) }}</strong></a>
                        <span class="meta">{{ __('of') }} <a href="{{ route('records.show', $unit) }}">{{ $unit->described('title', [app()->getLocale(), 'en']) }}</a>
                            @if($current) · {{ $current->state->label(\App\Support\Vocab::languages()) }}@endif</span>
                        @if($caption)<span class="summary">{{ __('Its embedded caption: :caption', ['caption' => \Illuminate\Support\Str::limit($caption, 300)]) }}</span>@endif
                        @if($mayUpdate)
                            <form method="post" action="{{ route('alt-text.store', $instantiation) }}">@csrf
                                <input type="hidden" name="language" value="{{ $lang }}"><input type="hidden" name="show" value="{{ $show }}"><input type="hidden" name="page" value="{{ $worklist->currentPage() }}">
                                <label for="alt-{{ $instantiation->id }}">{{ __('Alternative text in :lang', ['lang' => $lang]) }}</label>
                                <textarea id="alt-{{ $instantiation->id }}" name="alt_text" rows="2" maxlength="{{ $maxAltLen }}" lang="{{ $lang }}" style="width: 100%">{{ $current?->value }}</textarea>
                                @if($user->may('publish', 'description', $unit))<label style="font-weight: normal"><input type="checkbox" name="publish" value="1"> {{ __('Publish it') }}</label>@endif
                                <button type="submit">{{ __('Save') }}</button>
                            </form>
                        @elseif($current)
                            <p lang="{{ $lang }}">{{ $current->value }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        @if($worklist->hasPages())
            <nav aria-label="{{ __('Pages') }}"><p>
                @if($worklist->previousPageUrl())<a href="{{ $worklist->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
                {{ __('Page :page of :pages', ['page' => $worklist->currentPage(), 'pages' => $worklist->lastPage()]) }}
                @if($worklist->nextPageUrl())<a href="{{ $worklist->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@endif
            </p></nav>
        @endif
    @endif
@endsection
