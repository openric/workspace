{{-- OpenRiC Workspace - linking a guide to others by hand (Heratio's help manage-links; roadmap phase 20b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', __('Related guides').': '.$article->title)
@section('content')
    <h1>{{ __('Related guides') }}: {{ $article->title }}</h1>
    <p><a href="{{ route('help.article', $article->slug) }}">{{ __('Back to the guide') }}</a></p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>{{ __('A guide is linked to every guide its text links to; link others here. A link joins both guides, and is kept when the guides are read in again.') }}</p>
    <ul>@forelse($related as $r)<li><a href="{{ route('help.article', $r->slug) }}">{{ $r->title }}</a>
        <form method="post" action="{{ route('help.links.destroy', [$article->slug, $r->id]) }}" style="display: inline">@csrf @method('DELETE')<button type="submit" style="margin: 0; padding: .1rem .5rem">{{ __('Remove') }}</button></form></li>
    @empty<li>{{ __('None yet.') }}</li>@endforelse</ul>
    <form method="post" action="{{ route('help.links.store', $article->slug) }}">@csrf<label for="hl">{{ __('Link to') }}</label>
        <select id="hl" name="target">@foreach($others->groupBy('category') as $cat => $list)<optgroup label="{{ __($cat) }}">@foreach($list as $o)<option value="{{ $o->id }}">{{ $o->title }}</option>@endforeach</optgroup>@endforeach</select> <button type="submit">{{ __('Link') }}</button></form>
@endsection
