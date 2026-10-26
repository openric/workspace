{{-- OpenRiC Workspace - the catalogue home page's carousel of collections. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Reused from Heratio's heritage landing page (ahg-heritage-manage landing.blade.php and public/css/heritage-landing.css): the scrolling track of collection cards, the arrows, the dots and the rotation every five seconds, paused while pointed at or focused. Changed here: openric.org's tokens instead of Heratio's colours, inline icons instead of Font Awesome, a pause button (WCAG 2.2.2), no movement when the reader asks for reduced motion, and dots that say which one is current. --}}
{{-- Needs $featured (DiscoverController::featured) and $view. --}}
<style>
    .collections-carousel { position: relative; margin: 1rem 0 2rem; }
    .collections-track { display: flex; gap: 1.25rem; overflow-x: auto; scroll-behavior: smooth; scroll-snap-type: x mandatory; scrollbar-width: none; padding: 2px 2px 1rem; }
    .collections-track::-webkit-scrollbar { display: none; }
    .collection-card { flex: 0 0 auto; width: 320px; scroll-snap-align: start; background: var(--bg); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); color: var(--ink); text-decoration: none !important; transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease; }
    .collection-card:hover, .collection-card:focus-visible { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: var(--link); }
    .collection-card img { display: block; width: 100%; height: 220px; object-fit: cover; background: var(--surface-2); }
    .collection-card-body { padding: .9rem 1.1rem 1rem; }
    .collection-card-title { font-size: 1.05rem; font-weight: 600; margin: 0 0 .25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--ink); }
    .collection-card-summary { font-size: .88rem; color: var(--muted-2); margin: 0 0 .5rem; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .collection-card-count { font-size: .8rem; color: var(--link); font-weight: 500; }
    .carousel-arrow { position: absolute; top: 110px; width: 44px; height: 44px; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--bg); color: var(--ink); border: 1px solid var(--line); box-shadow: var(--shadow-lg); z-index: 5; }
    .carousel-arrow:hover { background: var(--surface-2); color: var(--accent); }
    .carousel-arrow.left { left: -22px; } .carousel-arrow.right { right: -22px; }
    .carousel-arrow svg, .carousel-pause svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .carousel-controls { display: flex; justify-content: center; align-items: center; gap: .5rem; margin-top: .25rem; }
    .carousel-dot { width: 10px; height: 10px; min-height: 0; margin: 0; padding: 0; border-radius: 50%; border: 0; background: var(--line); box-shadow: none; }
    .carousel-dot[aria-current="true"] { background: var(--link); transform: scale(1.25); }
    .carousel-pause { margin: 0 0 0 .5rem; padding: .25rem .6rem; font-size: .8rem; display: inline-flex; align-items: center; gap: .3rem; background: var(--bg); color: var(--muted-2); border: 1px solid var(--line); box-shadow: none; }
    @media (max-width: 1199px) { .carousel-arrow { display: none; } }
    @media (max-width: 40rem) { .collection-card { width: 78vw; } .collection-card img { height: 180px; } }
    @media (prefers-reduced-motion: reduce) { .collections-track { scroll-behavior: auto; } .collection-card { transition: none; } }
</style>
<section class="collections-carousel" aria-roledescription="carousel" aria-label="Collections" data-autorotate="5000">
    <button type="button" class="carousel-arrow left" aria-label="Previous collections" data-scroll="-1"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg></button>
    <div class="collections-track" id="collections-track" tabindex="-1">
        @foreach($featured as $i => ['unit' => $unit, 'file' => $file, 'count' => $count, 'summary' => $summary])
            <a href="{{ route('discover.record', $unit->id) }}" class="collection-card" aria-roledescription="slide" aria-label="{{ $i + 1 }} of {{ $featured->count() }}: {{ $view->label($unit) }}">
                <img src="{{ route('discover.file', $file) }}" alt="" loading="{{ $i < 3 ? 'eager' : 'lazy' }}" width="{{ $file->width }}" height="{{ $file->height }}">
                <div class="collection-card-body">
                    <h3 class="collection-card-title">{{ $view->label($unit) }}</h3>
                    @if($summary)<p class="collection-card-summary">{{ $summary }}</p>@endif
                    <span class="collection-card-count">{{ $unit->level?->label(\App\Support\Vocab::languages()) }}@if($count) · {{ __($count === 1 ? ':count unit inside' : ':count units inside', ['count' => $count]) }} @endif</span>
                </div>
            </a>
        @endforeach
    </div>
    <button type="button" class="carousel-arrow right" aria-label="Next collections" data-scroll="1"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg></button>
    <div class="carousel-controls" hidden>
        <span class="carousel-dots"></span>
        <button type="button" class="carousel-pause" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5v14M15 5v14"/></svg><span>{{ __('Pause') }}</span></button>
    </div>
</section>
<script>
    // Heratio's carousel (ahg-heritage-manage landing.blade.php), kept as it was apart from the pause button and reduced motion.
    (function () {
        const carousel = document.querySelector('.collections-carousel');
        if (!carousel) return;
        const track = carousel.querySelector('.collections-track');
        const cards = track.querySelectorAll('.collection-card');
        const step = () => (cards[0] ? cards[0].getBoundingClientRect().width + 20 : 320);
        carousel.querySelectorAll('.carousel-arrow').forEach(btn => btn.addEventListener('click', () => track.scrollBy({left: step() * parseInt(btn.dataset.scroll, 10), behavior: 'smooth'})));

        const positions = Math.max(1, cards.length - Math.floor(track.offsetWidth / step()) + 1);
        if (positions <= 1) return;
        const controls = carousel.querySelector('.carousel-controls');
        const dots = carousel.querySelector('.carousel-dots');
        const pause = carousel.querySelector('.carousel-pause');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let current = 0, timer = null, hovering = false, stopped = reduced;
        controls.hidden = false;
        for (let i = 0; i < positions; i++) {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'carousel-dot';
            dot.setAttribute('aria-label', 'Go to collection ' + (i + 1));
            dot.addEventListener('click', () => go(i));
            dots.appendChild(dot);
        }
        function mark() { dots.querySelectorAll('.carousel-dot').forEach((d, i) => d.setAttribute('aria-current', i === current ? 'true' : 'false')); }
        function go(i) { current = i; track.scrollTo({left: i * step(), behavior: reduced ? 'auto' : 'smooth'}); mark(); }
        function tick() { if (!hovering && !stopped) go((current + 1) % positions); }
        function setStopped(value) {
            stopped = value;
            pause.setAttribute('aria-pressed', value ? 'true' : 'false');
            pause.querySelector('span').textContent = value ? 'Play' : 'Pause';
            pause.querySelector('svg').innerHTML = value ? '<path d="M7 5l12 7-12 7z"/>' : '<path d="M9 5v14M15 5v14"/>';
        }
        pause.addEventListener('click', () => setStopped(!stopped));
        carousel.addEventListener('mouseenter', () => { hovering = true; });
        carousel.addEventListener('mouseleave', () => { hovering = false; });
        carousel.addEventListener('focusin', () => { hovering = true; });
        carousel.addEventListener('focusout', () => { hovering = false; });
        track.addEventListener('scroll', () => { const p = Math.round(track.scrollLeft / step()); if (p !== current && p >= 0 && p < positions) { current = p; mark(); } });
        setStopped(reduced);
        mark();
        timer = setInterval(tick, parseInt(carousel.dataset.autorotate, 10) || 5000);
    })();
</script>
