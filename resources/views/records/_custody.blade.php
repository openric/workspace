{{-- OpenRiC Workspace - a record's custody history, its provenance chain in order (Heratio's "Provenance and chain of custody" on the description). Needs $steps (with their relations), $languages; $public (with $view) leaves out the sources, which may name accessions, and names an owner only when their authority record is published. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<ol style="padding-left: 1.4rem">
    @foreach($steps as $step)
        @php($named = ! ($public ?? false) || $step->owner === null || $view->readable($step->owner))
        <li id="step-{{ $step->id }}" style="margin: .3rem 0"><strong>{{ $named ? $step->owner_name : __('Private owner') }}</strong>@if($step->ownerType) ({{ $step->ownerType->label($languages) }})@endif:
            {{ $step->transferType->label($languages) }}, {{ $step->period($languages) }}@if($step->owner_location && $named), {{ $step->owner_location }}@endif
            @if($step->certainty)<small>; {{ $step->certainty->label($languages) }}</small>@endif
            @if($step->is_gap)<br><em>{{ __('Gap in the provenance') }}@if($step->gap_explanation): {{ $step->gap_explanation }}@endif</em>@endif
            @if($step->sources && ! ($public ?? false))<br><small>{{ __('Sources') }}: {{ $step->sources }}</small>@endif</li>
    @endforeach
</ol>
