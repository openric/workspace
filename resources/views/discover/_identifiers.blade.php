{{-- OpenRiC Workspace - an entity's identifiers in the public catalogue, other than its own local one (shown as its reference code). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $entity. A web address, such as an Archives nationales URI, is a link. --}}
@php($shown = $entity->identifiers()->with('identifierType.labels')->get()->reject(fn ($i) => $i->identifierType->notation === 'local'))
@if($shown->isNotEmpty())
    <section aria-labelledby="identifiers-heading">
        <h2 id="identifiers-heading">{{ __('Identifiers') }} @include('description._ric', ['term' => 'rico:Identifier'])</h2>
        <dl class="values">
            @foreach($shown as $identifier)
                <dt>{{ $identifier->identifierType->label(\App\Support\Vocab::languages()) }}</dt>
                <dd>@if(preg_match('~^https?://~', $identifier->value))<a href="{{ $identifier->value }}" rel="noopener">{{ $identifier->value }}</a>@else{{ $identifier->value }}@endif</dd>
            @endforeach
        </dl>
    </section>
@endif
