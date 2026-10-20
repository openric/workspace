{{-- OpenRiC Workspace - Edit and Delete beside an entity in a list, as far as the account may. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $entity: a unit of description, a repository or a place. --}}
@php($kindDefinition = \App\Relations\EntityKinds::get(\App\Relations\EntityKinds::of($entity)))
@php($editRoute = \Illuminate\Support\Str::before($kindDefinition['route'], '.').'.edit')
@php($mayEdit = auth()->user()->may('update', $kindDefinition['permission'], $entity))
@php($mayDelete = auth()->user()->may('delete', $kindDefinition['permission'], $entity))
@if($mayEdit || $mayDelete)
    <small>@if($mayEdit) · <a href="{{ route($editRoute, [$entity, 'language' => $entity->originalLanguage($kindDefinition['label'])]) }}">Edit</a>@endif
        @if($mayDelete) · <a href="{{ route('deletion.confirm', [$kindDefinition['segment'], $entity->getKey()]) }}">Delete</a>@endif</small>
@endif
