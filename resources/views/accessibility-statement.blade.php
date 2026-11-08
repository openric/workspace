{{-- OpenRiC Workspace - the accessibility statement (roadmap R1 hardening), after Heratio's /accessibility-statement and the W3C model statement: the commitment, the conformance status, what is accessible, the known limitations, how to report a barrier and how the statement was prepared. Honest by design: it claims only what the Workspace's own checks show. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.public')
@section('title', __('Accessibility statement'))
@section('content')
    @php($name = $institution?->name ?? config('app.name'))
    <h1>{{ __('Accessibility statement') }}</h1>

    <h2>{{ __('Our commitment') }}</h2>
    <p>{{ __(':name wants its catalogue to be usable by as many people as possible: by keyboard, with a screen reader, zoomed in, and on a phone.', ['name' => $name]) }}</p>

    <h2>{{ __('Conformance status') }}</h2>
    <p>{{ __('The catalogue runs on the OpenRiC Workspace (version :version). It targets WCAG 2.2 at level AA, the international baseline, which harmonised standards such as EN 301 549 also refer to. It is partially conformant: some parts do not yet fully meet the standard, as listed below.', ['version' => trim((string) @file_get_contents(base_path('VERSION')))]) }}</p>

    <h2>{{ __('What has been done') }}</h2>
    <ul>
        <li>{{ __('Every release is checked automatically with axe-core against WCAG 2.2 A and AA on the main public and staff pages, and is not released while that check finds a critical or serious failure.') }}</li>
        <li>{{ __('The main steps of describing and publishing a record are checked to work by keyboard alone, with the focus always visible and never trapped.') }}</li>
        <li>{{ __('Every page has a link past its menu to its content.') }}</li>
        <li>{{ __('Images carry the text alternative their description gives; a 3D model\'s hotspots are also listed as text, and it can have a turntable video.') }}</li>
        <li>{{ __('Turning, animation and smooth movement stop for a reader who asks their system for reduced motion.') }}</li>
    </ul>

    <h2>{{ __('Known limitations') }}</h2>
    <ul>
        <li>{{ __('Automated checks find about a third of accessibility failures. The Workspace has not yet been reviewed by people using screen readers and other assistive technology; that review is planned before version 0.1.') }}</li>
        <li>{{ __('Scanned documents without a transcription are images: their text cannot be read aloud until a transcription is added.') }}</li>
        <li>{{ __('The deep-zoom image viewer, the 3D viewer and the graph of relationships are third-party components that are not fully usable by keyboard or screen reader; the same information is on the page as text.') }}</li>
        <li>{{ __('PDF files are shown as they were received; older ones may not be tagged for screen readers.') }}</li>
        <li>{{ __('Descriptions are written by archivists and contributors, who may not give every image a text alternative.') }}</li>
    </ul>

    <h2>{{ __('Report a barrier') }}</h2>
    <p>{{ __('If something in the catalogue is hard or impossible for you to use, tell us through the feedback form, saying which page and what happened. Your message reaches the staff of :name.', ['name' => $name]) }} <a href="{{ route('feedback.create') }}">{{ __('Feedback') }}</a></p>

    <h2>{{ __('Preparation of this statement') }}</h2>
    <p>{{ __('This statement describes the OpenRiC Workspace software, version :version, from its own automated and keyboard checks. It was not prepared by a review of the records in this catalogue.', ['name' => $name, 'version' => trim((string) @file_get_contents(base_path('VERSION')))]) }}</p>
@endsection
