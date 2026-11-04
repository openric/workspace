{{-- OpenRiC Workspace - one block of a landing page, by its type (Heratio's landing-page block partials; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $block, with ->data from BlockData, and $pages (LandingPages). Every value is escaped; text blocks are Markdown with HTML stripped. --}}
@php($type = $block->type->notation)
@php($c = $pages->config($block))
@php($data = $block->data)
@php($style = collect(['padding-top' => $block->padding_top.'rem', 'padding-bottom' => $block->padding_bottom.'rem', 'background' => $block->background_color, 'color' => $block->text_color])->filter()->map(fn ($v, $k) => "$k: $v")->join('; '))
<section id="block-{{ $block->id }}" @class(['lp-block', 'lp-'.$type, 'lp-full' => $block->full_width, $block->css_classes]) style="{{ $style }}" @if($block->title) aria-label="{{ $block->title }}" @endif>
    @switch($type)
        @case('row-1-col') @case('row-2-col') @case('row-3-col')
            @php($n = \App\Site\LandingPages::ROWS[$type])
            @php($widths = $type === 'row-2-col' ? [(int) $c['left_width'], (int) $c['right_width']] : ($type === 'row-3-col' ? array_map('intval', array_pad((array) $c['col_widths'], 3, 4)) : [12]))
            <div class="lp-row" style="grid-template-columns: {{ collect($widths)->map(fn ($w) => max(1, min(12, $w)).'fr')->join(' ') }}">
                @for($i = 1; $i <= $n; $i++)<div>@foreach($block->children->where('column_slot', $i) as $child)@include('site._block', ['block' => $child])@endforeach</div>@endfor
            </div>
            @break
        @case('hero-banner')
            <div class="lp-hero" style="min-height: {{ max(120, min(1200, (int) $c['height'])) }}px; text-align: {{ in_array($c['text_align'], ['left', 'center', 'right'], true) ? $c['text_align'] : 'center' }}; --overlay: {{ max(0, min(1, (float) $c['overlay_opacity'])) }}; @if($c['background_image'])background-image: url('{{ $c['background_image'] }}');@endif">
                @if($c['title'])<h1>{{ $c['title'] }}</h1>@endif
                @if($c['subtitle'])<p>{{ $c['subtitle'] }}</p>@endif
                @if($c['button_text'] && $c['button_url'])<p><a class="button" href="{{ $c['button_url'] }}">{{ $c['button_text'] }}</a></p>@endif
            </div>
            @break
        @case('text-content')
            <div class="site-text">{!! \App\Http\Controllers\SiteController::markdown($c['content']) !!}</div>
            @break
        @case('image-carousel')
            @if($data)<ul class="lp-carousel">@foreach($data as $img)<li>@if($img['href'])<a href="{{ $img['href'] }}">@endif<img src="{{ $img['src'] }}" alt="{{ $img['caption'] }}" loading="lazy">@if($img['href'])</a>@endif @if($img['caption'])<p class="meta">{{ $img['caption'] }}</p>@endif</li>@endforeach</ul>@endif
            @break
        @case('search-box')
            <form method="get" action="{{ route('discover.home') }}" role="search" class="catalogue-search"><label for="lp-q-{{ $block->id }}">{{ __('Search the catalogue') }}</label>
                <div><input id="lp-q-{{ $block->id }}" name="q" required placeholder="{{ __($c['placeholder']) }}"><button type="submit">{{ __('Search') }}</button></div></form>
            @break
        @case('statistics')
            @if($c['title'])<h2>{{ $c['title'] }}</h2>@endif
            <div class="lp-stats">@foreach($data ?? [] as $stat)<div><strong>{{ number_format($stat['count']) }}</strong> <span class="meta">{{ __($stat['label'] ?? '') }}</span></div>@endforeach</div>
            @break
        @case('browse-panels')
            <ul class="lp-cards" style="grid-template-columns: repeat({{ max(1, min(6, (int) $c['columns'])) }}, minmax(0, 1fr))">@foreach($data ?? [] as $panel)<li><div>
                @if($panel['href'])<a href="{{ $panel['href'] }}"><strong>{{ __($panel['title'] ?? '') }}</strong></a>@else<strong>{{ __($panel['title'] ?? '') }}</strong>@endif
                @if($c['show_counts'] && $panel['count'] !== null)<br><span class="meta">{{ number_format($panel['count']) }}</span>@endif</div></li>@endforeach</ul>
            @break
        @case('recent-items') @case('featured-items')
            @if($c['title'])<h2>{{ __($c['title']) }}</h2>@endif
            @if($data)<ul class="lp-cards" style="grid-template-columns: repeat({{ max(1, min(6, (int) $c['columns'])) }}, minmax(0, 1fr))">@foreach($data as $card)<li>
                @if(($c['show_thumbnail'] ?? true) && $card['thumbnail'])<a href="{{ $card['href'] }}"><img src="{{ $card['thumbnail'] }}" alt="" loading="lazy"></a>@endif
                <div><a href="{{ $card['href'] }}">{{ $card['title'] }}</a><br><span class="meta">{{ $card['level'] }}@if(($c['show_date'] ?? false) && $card['date']) · {{ $card['date'] }}@endif</span>
                    @if(($c['show_description'] ?? false) && $card['summary'])<p class="meta">{{ $card['summary'] }}</p>@endif</div></li>@endforeach</ul>
            @else<p class="meta">{{ __('Nothing is published yet.') }}</p>@endif
            @break
        @case('holdings-list')
            @if($c['title'])<h2>{{ __($c['title']) }}</h2>@endif
            <ul>@forelse($data ?? [] as $h)<li><a href="{{ $h['href'] }}">{{ $h['title'] }}</a>@if($c['show_level'] && $h['level']) <span class="meta">{{ $h['level'] }}@if($h['reference']), {{ $h['reference'] }}@endif</span>@endif</li>@empty<li class="meta">{{ __('Nothing is published yet.') }}</li>@endforelse</ul>
            @break
        @case('repository-spotlight')
            @if($data)
                <h2><a href="{{ $data['href'] }}">{{ $data['name'] }}</a></h2>
                @if($c['show_description'] && $data['description'])<p>{{ $data['description'] }}</p>@endif
                @if($c['show_contact'] && $data['contact'])<p class="meta">{{ collect([$data['contact']->street_address, $data['contact']->city, $data['contact']->telephone, $data['contact']->email])->filter()->join(' · ') }}</p>@endif
                @if($data['holdings'])<ul>@foreach($data['holdings'] as $h)<li><a href="{{ $h['href'] }}">{{ $h['title'] }}</a></li>@endforeach</ul>@endif
            @endif
            @break
        @case('map')
            @if($c['title'])<h2>{{ __($c['title']) }}</h2>@endif
            @if(! $data)<p class="meta">{{ __('No places with coordinates are published yet.') }}</p>
            @else
                <link rel="stylesheet" href="{{ asset('vendor/leaflet/1.9.4/leaflet.min.css') }}">
                <div id="map-{{ $block->id }}" style="height: {{ max(150, min(1200, (int) $c['height'])) }}px; border: 1px solid var(--line); border-radius: var(--radius)"></div>
                <script src="{{ asset('vendor/leaflet/1.9.4/leaflet.min.js') }}"></script>
                <script>
                    (function () {
                        const places = @json($data);
                        const map = L.map(@json('map-'.$block->id)).setView([places[0].latitude, places[0].longitude], {{ max(1, min(18, (int) $c['zoom'])) }});
                        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'}).addTo(map);
                        places.forEach(function (p) {
                            const link = document.createElement('a'); link.href = p.href; link.textContent = p.name;
                            L.circleMarker([p.latitude, p.longitude], {radius: 7}).addTo(map).bindPopup(link);
                        });
                        if (places.length > 1) { map.fitBounds(places.map(function (p) { return [p.latitude, p.longitude]; }), {padding: [20, 20]}); }
                    })();
                </script>
                <noscript><ul>@foreach($data as $p)<li><a href="{{ $p['href'] }}">{{ $p['name'] }}</a> <span class="meta">{{ $p['latitude'] }}, {{ $p['longitude'] }}</span></li>@endforeach</ul></noscript>
            @endif
            @break
        @case('header-section')
            @if($c['show_breadcrumb'])<p class="meta"><a href="{{ route('discover.home') }}">{{ __('Catalogue') }}</a></p>@endif
            @if($c['title'])<h1>{{ $c['title'] }}</h1>@endif
            @if($c['subtitle'])<p class="catalogue-lede">{{ $c['subtitle'] }}</p>@endif
            @break
        @case('footer-section')
            <div class="lp-footer">@foreach((array) $c['columns'] as $col)<div>@if(! empty($col['title']))<h2 style="font-size: 1rem">{{ $col['title'] }}</h2>@endif
                <ul>@foreach((array) ($col['links'] ?? []) as $l)@if(\App\Site\LandingPages::url($l['url'] ?? ''))<li><a href="{{ \App\Site\LandingPages::url($l['url']) }}">{{ $l['text'] ?? $l['url'] }}</a></li>@endif @endforeach</ul></div>@endforeach</div>
            @break
        @case('quick-links')
            <ul class="lp-links">@foreach((array) $c['links'] as $l)@if(\App\Site\LandingPages::url($l['url'] ?? ''))<li><a href="{{ \App\Site\LandingPages::url($l['url']) }}">{{ $l['text'] ?? $l['url'] }}</a></li>@endif @endforeach</ul>
            @break
        @case('copyright-bar')
            <p class="meta" style="text-align: center">&copy; {{ $c['year'] === 'auto' ? now()->year : $c['year'] }} {{ $c['organisation'] ?: $institution->name }}. {{ __($c['text']) }}</p>
            @break
        @case('spacer')
            <div style="height: {{ max(0, min(20, (float) $c['height'])) }}rem" aria-hidden="true"></div>
            @break
        @case('divider')
            <hr style="border: 0; border-top: 1px {{ in_array($c['style'], ['solid', 'dashed', 'dotted'], true) ? $c['style'] : 'solid' }} {{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $c['color']) ? $c['color'] : '#dee2e6' }}; width: {{ max(5, min(100, (int) $c['width'])) }}%; margin: 0 auto">
            @break
    @endswitch
</section>
