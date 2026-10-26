{{-- OpenRiC Workspace - the RiC-O term a field is kept or exported as, linked to its definition. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $term: a prefixed term such as rico:scopeAndContent or openricx:generalContext, a concept (its ricTerm()), xml:lang (the value's RDF language tag), or null (not in RiC-O 1.1). --}}
@php($term = $term instanceof \App\Models\Concept ? $term->ricTerm() : $term)
@php([$prefix, $local] = $term ? explode(':', $term, 2) : [null, null])
@php($namespace = ['rico' => 'https://www.ica.org/standards/RiC/ontology#', 'openricx' => 'https://openric.org/ns/ext/v1#'][$prefix] ?? null)
<small class="ric">@if($namespace)<a href="{{ $namespace.$local }}" title="{{ __('The RiC term this is kept or exported as') }}"><code>{{ $term }}</code></a>@elseif($term)<code title="{{ __('Kept as the language tag of the RDF value') }}">{{ $term }}</code>@else<span title="{{ __('RiC-O 1.1 has no term for this; kept by the Workspace') }}">{{ __('not in RiC-O') }}</span>@endif</small>
