{{-- OpenRiC Workspace - the footer of every page, public and staff (phase 26): the institution and its own text (Settings), the static pages it gives a place in the footer (Public site, Static pages), help and accessibility, and the open data. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($footerOf = $institution ?? \App\Models\Institution::current())
<footer class="site-footer no-print">
    <div class="site-footer-inner">
        <section aria-label="{{ $footerOf?->headerTitle() ?? config('app.name') }}">
            <p class="site-footer-name">{{ $footerOf?->headerTitle() ?? config('app.name') }}</p>
            @if($footerOf?->footer_text)<p style="white-space: pre-line">{{ $footerOf->footer_text }}</p>@endif
        </section>
        <nav aria-label="{{ __('About the institution') }}">
            <ul>
                @if($footerOf)
                    @foreach(app(\App\Site\StaticPages::class)->footer($footerOf) as $sitePage)
                        <li><a href="{{ \Illuminate\Support\Facades\Route::has('site.'.$sitePage->slug) ? route('site.'.$sitePage->slug) : route('site.page', $sitePage->slug) }}">{{ $sitePage->translation(\App\Support\Vocab::languages())?->title }}</a></li>
                    @endforeach
                    @if(\App\Support\Modules::on('articles') && app(\App\Site\Articles::class)->published($footerOf)->exists())<li><a href="{{ route('site.articles-public') }}">{{ __('Articles') }}</a></li>@endif
                @endif
                <li><a href="{{ route('help.index') }}">{{ __('Help') }}</a></li>
                <li><a href="{{ route('accessibility.statement') }}">{{ __('Accessibility') }}</a></li>
            </ul>
        </nav>
        <nav aria-label="{{ __('Open data') }}">
            <ul>
                <li><a href="{{ route('api.service') }}">{{ __('OpenRiC API') }}</a></li>
                <li><a href="{{ route('api.oai') }}?verb=Identify">OAI-PMH</a></li>
                @if(\App\Federation\TripleStore::configured())<li><a href="{{ route('discover.explore') }}">{{ __('Explore the graph') }}</a></li>@endif
            </ul>
        </nav>
    </div>
    <p class="site-footer-base">{!! __('Described in RiC (Records in Contexts). The data is open through the :api and :oai.', ['api' => '<a href="'.e(route('api.service')).'">'.e(__('OpenRiC API')).'</a>', 'oai' => '<a href="'.e(route('api.oai')).'?verb=Identify">OAI-PMH</a>']) !!}</p>
</footer>
