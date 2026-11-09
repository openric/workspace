{{-- OpenRiC Workspace - keep an entity among one's favourites, or forget it (Heratio's favourites toggle; roadmap phase 19a). Needs $entity. Shown to a signed-in account. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@auth
    @php($kept = app(\App\Research\Favourites::class)->has(auth()->user(), $entity))
    <form method="post" action="{{ route('favourites.toggle', [\App\Relations\EntityKinds::get(\App\Relations\EntityKinds::of($entity))['segment'], $entity->getKey()]) }}" class="no-print" style="display: inline">
        @csrf
        <button type="submit" aria-pressed="{{ $kept ? 'true' : 'false' }}" style="margin: .25rem 0; padding: .3rem .8rem; font-size: .85rem">{{ $kept ? __('In your favourites: remove') : __('Add to favourites') }}</button>
    </form>
    @module('research')@include('research._entity-tools', ['entity' => $entity])@endmodule
@endauth
