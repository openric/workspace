{{-- OpenRiC Workspace - the units nearest this one in meaning, as the same audience sees them (Heratio's related records; roadmap phase 17d1). Needs $unit, $public and $route (the page route for a unit). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($related = app(\App\Search\Meaning::class)->settings($unit->institution_id)->by_meaning ? app(\App\Search\Meaning::class)->related('record_resources', $unit->id, $public) : [])
{{-- Staff see only the related units they may read, as permissions can be set on one unit. --}}
@if($related && ! $public)
    @php($readable = \App\Models\RecordResource::whereIn('id', array_column($related, 'subject_id'))->get()->filter(fn ($u) => auth()->user()->may('read', 'description', $u))->keyBy('id'))
    @php($related = array_values(array_filter($related, fn ($r) => $readable->has($r->subject_id))))
@endif
@if($related)
    <section aria-labelledby="related-h"><h2 id="related-h">{{ __('Related by meaning') }}</h2>
        <ul>@foreach($related as $r)<li><a href="{{ route($route, $r->subject_id) }}">{{ $r->title }}</a></li>@endforeach</ul></section>
@endif
