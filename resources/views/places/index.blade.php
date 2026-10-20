{{-- OpenRiC Workspace - places: search, filter and the hierarchy. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@extends('layouts.app')
@section('title', 'Places')
@section('content')
    <h1>Places</h1>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    <p>
        @if(auth()->user()->may('create', 'vocabulary'))<a href="{{ route('places.create') }}">New place</a> · @endif
        @if($tree)<a href="{{ route('places.index') }}">Search and list</a>@else<a href="{{ route('places.index', ['view' => 'tree']) }}">Show the hierarchy</a>@endif
    </p>
    @if($tree)
        @php
            // Each level sorted by name; a place lists the places it contains below it.
            $branch = function ($parentKey) use (&$branch, $tree) {
                $out = '';
                foreach (($tree[$parentKey] ?? collect())->sortBy(fn ($p) => mb_strtolower($p->described('place-name', [app()->getLocale(), 'en']))) as $place) {
                    $out .= '<li><a href="'.e(route('places.show', $place)).'">'.e($place->described('place-name', [app()->getLocale(), 'en'])).'</a>'
                        .($place->placeType ? ' <small>'.e($place->placeType->anyLabel(['en'])).'</small>' : '')
                        .(isset($tree[$place->id]) ? '<ul>'.$branch($place->id).'</ul>' : '').'</li>';
                }

                return $out;
            };
        @endphp
        @if(isset($tree['top']))<ul>{!! $branch('top') !!}</ul>@else<p>No places yet.</p>@endif
    @else
        <form method="get" action="{{ route('places.index') }}" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end">
            <div><label for="q" style="margin-top: 0">Name or identifier</label><input id="q" name="q" value="{{ $query }}" maxlength="200"></div>
            @if($types->isNotEmpty())
            <div><label for="type" style="margin-top: 0">Place type</label>
                <select id="type" name="type" style="font: inherit; padding: .5rem">
                    <option value="">Any</option>
                    @foreach($types as $t)<option value="{{ $t->id }}" @selected($type === $t->id)>{{ $t->anyLabel(['en']) }}</option>@endforeach
                </select></div>
            @endif
            <button type="submit" style="margin-top: 0">Search</button>
        </form>
        @forelse($places as $place)
            <p><a href="{{ route('places.show', $place) }}">{{ $place->described('place-name', [app()->getLocale(), 'en']) }}</a>{{ collect([$place->placeType?->anyLabel(['en']), $place->parent ? 'in '.$place->parent->described('place-name', [app()->getLocale(), 'en']) : null, $place->identifier])->filter()->map(fn ($s) => ', '.$s)->join('') }}</p>
        @empty
            <p>{{ $query !== '' || $type !== '' ? 'No place matches.' : 'No places yet.' }}</p>
        @endforelse
        @if($places->hasPages())
            <nav aria-label="Pages"><p>
                @if($places->previousPageUrl())<a href="{{ $places->previousPageUrl() }}" rel="prev">Previous</a>@endif
                Page {{ $places->currentPage() }} of {{ $places->lastPage() }}
                @if($places->nextPageUrl())<a href="{{ $places->nextPageUrl() }}" rel="next">Next</a>@endif
            </p></nav>
        @endif
    @endif
@endsection
