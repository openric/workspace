{{-- OpenRiC Workspace - an entity's research tools on its page (roadmap phase 19d): annotate it, add it to a collection. Needs $entity; shown to a signed-in account inside the favourites button. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($segment = \App\Relations\EntityKinds::get(\App\Relations\EntityKinds::of($entity))['segment'])
<a href="{{ route('research.studio', [$segment, $entity->getKey()]) }}" class="no-print" style="font-size: .85rem; margin-left: .5rem">{{ __('Annotate') }}</a>
@if($entity instanceof \App\Models\RecordResource)<a href="{{ route('research.assess', $entity) }}" class="no-print" style="font-size: .85rem; margin-left: .5rem">{{ __('Assess as a source') }}</a>@endif
<details class="no-print" style="display: inline-block; font-size: .85rem; margin-left: .5rem"><summary>{{ __('Add to a collection') }}</summary>
    <form method="post" action="{{ route('research.collections.add', [$segment, $entity->getKey()]) }}">@csrf
        @php($mine = app(\App\Research\Collections::class)->of(auth()->user()))
        @if($mine->isNotEmpty())<label for="ec-coll">{{ __('Collection') }}</label> <select id="ec-coll" name="collection_id">@foreach($mine as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select> {{ __('or') }}@endif
        <label for="ec-new">{{ __('a new one named') }}</label> <input id="ec-new" name="new_name" size="20" @if($mine->isEmpty()) required @endif>
        <label for="ec-notes">{{ __('Notes') }}</label> <input id="ec-notes" name="notes" size="20"> <button type="submit">{{ __('Add') }}</button></form>
</details>
