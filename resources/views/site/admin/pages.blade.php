{{-- OpenRiC Workspace - the institution's static pages (Heratio's static page browse; roadmap phase 20a). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Static pages'))
@section('content')
    <h1>{{ __('Static pages') }}</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('Pages of the public site that are not part of the catalogue, written in Markdown in each language. About, privacy and terms are linked from the foot of every public page once they exist.') }}</p>
    <p><a href="{{ route('site.pages.create') }}">{{ __('New page') }}</a>@foreach($named as $n)@if(! $pages->contains('slug', $n)) · <a href="{{ route('site.pages.create', ['slug' => $n]) }}">{{ __('Write the :page page', ['page' => $n]) }}</a>@endif @endforeach</p>
    <table><thead><tr><th scope="col">{{ __('Address') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Languages') }}</th><th scope="col">{{ __('Changed') }}</th></tr></thead>
        <tbody>@forelse($pages as $p)<tr><td><a href="{{ in_array($p->slug, $named, true) ? route('site.'.$p->slug) : route('site.page', $p->slug) }}">/{{ in_array($p->slug, $named, true) ? '' : 'pages/' }}{{ $p->slug }}</a></td>
            <td><a href="{{ route('site.pages.edit', $p) }}">{{ $p->translation(\App\Support\Vocab::languages())?->title }}</a></td>
            <td>@foreach($p->translations as $t)<a href="{{ route('site.pages.edit', [$p, 'language' => $t->language]) }}">{{ $t->language }}</a> @endforeach</td><td>{{ $p->updated_at->format('Y-m-d') }}</td></tr>
        @empty<tr><td colspan="4">{{ __('None yet.') }}</td></tr>@endforelse</tbody></table>
@endsection
