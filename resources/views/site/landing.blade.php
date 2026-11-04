{{-- OpenRiC Workspace - a page built of blocks: a landing page of the institution, or a user's dashboard (Heratio's landing page; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', $page->meta_title ?: $page->name)
@section('head')
    @if($page->meta_description)<meta name="description" content="{{ $page->meta_description }}">@endif
    <style>
        .lp-block { padding-left: 0; padding-right: 0; } .lp-full { margin-left: calc(50% - 50vw); margin-right: calc(50% - 50vw); padding-left: 1rem; padding-right: 1rem; }
        .lp-row { display: grid; gap: 1.5rem; } .lp-row > div > .lp-block:first-child { padding-top: 0; }
        .lp-hero { display: flex; flex-direction: column; justify-content: center; padding: 2rem; border-radius: var(--radius); background-size: cover; background-position: center; position: relative; color: #fff; overflow: hidden; }
        .lp-hero::before { content: ""; position: absolute; inset: 0; background: #000; opacity: var(--overlay, .5); } .lp-hero > * { position: relative; }
        .lp-hero h1, .lp-hero h2 { color: #fff; border: 0; } .lp-hero a.button { display: inline-block; background: var(--link); color: #fff; padding: .5rem 1rem; border-radius: var(--radius); }
        .lp-cards { display: grid; gap: 1rem; list-style: none; padding: 0; } .lp-cards li { margin: 0; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface); overflow: hidden; }
        .lp-cards img { width: 100%; height: 9rem; object-fit: cover; display: block; } .lp-cards div { padding: .7rem .9rem; }
        .lp-stats { display: flex; flex-wrap: wrap; justify-content: space-around; gap: 1rem; text-align: center; } .lp-stats strong { display: block; font-size: 2rem; }
        .lp-carousel { display: flex; gap: .75rem; overflow-x: auto; scroll-snap-type: x mandatory; list-style: none; padding: 0 0 .4rem; }
        .lp-carousel li { flex: none; width: min(22rem, 80vw); scroll-snap-align: start; margin: 0; } .lp-carousel img { width: 100%; height: 14rem; object-fit: cover; border-radius: var(--radius); display: block; }
        .lp-links { display: flex; flex-wrap: wrap; gap: .6rem; list-style: none; padding: 0; } .lp-links a { display: inline-block; padding: .4rem .9rem; border: 1px solid var(--line); border-radius: var(--radius); }
        .lp-footer { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1rem; }
        @media (max-width: 40rem) { .lp-row { grid-template-columns: 1fr !important; } }
    </style>
    @if($page->custom_css)<style>{!! $page->custom_css !!}</style>@endif
@endsection
@section('content')
    <div class="landing-page {{ $page->css_classes }}">
        @foreach($blocks as $block)@include('site._block', ['block' => $block])@endforeach
        @if($blocks->isEmpty())<p class="meta">{{ __('This page has no blocks yet.') }}</p>@endif
        @unless($page->owner_id)<p class="meta"><a href="{{ route('discover.home', ['catalogue' => 1]) }}">{{ __('Browse the whole catalogue') }}</a></p>@endunless
    </div>
@endsection
