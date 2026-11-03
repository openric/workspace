{{-- OpenRiC Workspace - an entity's own fields, shown on its page, with the way to change them (roadmap phase 22b). Needs $entity and $kind; $public shows only what the public sees. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($cfValues = app(\App\CustomFields\CustomFields::class)->values($entity)->filter(fn ($vs) => $vs->first()->definition->is_active && (! ($public ?? false) || $vs->first()->definition->is_visible_public)))
@if($cfValues->isNotEmpty() || (! ($public ?? false) && \App\Models\CustomFieldDefinition::where(['institution_id' => $entity->institution_id, 'entity_table' => $entity->getTable(), 'is_active' => true])->exists()))
    <section aria-labelledby="cf-heading"><h2 id="cf-heading">{{ __('More about it') }} @include('description._ric', ['term' => null])</h2>
        @if($cfValues->isNotEmpty())<dl>@foreach($cfValues->sortBy(fn ($vs) => [$vs->first()->definition->field_group, $vs->first()->definition->sort_order]) as $vs)
            <dt>{{ $vs->first()->definition->label }}</dt><dd>{{ $vs->map(fn ($v) => \App\CustomFields\CustomFields::text($v, \App\Support\Vocab::languages()))->join('; ') }}</dd>@endforeach</dl>@endif
        @unless($public ?? false)@if(auth()->user()?->may('update', \App\CustomFields\CustomFields::KINDS[$entity->getTable()], $entity))<p><a href="{{ route('custom-fields.entity', [$kind, $entity->id]) }}">{{ __('Change these, or describe it through a form template') }}</a></p>@endif @endunless
    </section>
@endif
