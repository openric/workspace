{{-- OpenRiC Workspace - RepatriationClaimMail body (roadmap phase 17d2), after Heratio's RepatriationNotifier messages. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($community = $claim->claimant_community ?: __('an unspecified community'))
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
@if($toStatus === null)
<p>{{ $claim->current_holder ? __('A repatriation claim has been registered by :community regarding an object held by :holder.', ['community' => $community, 'holder' => $claim->current_holder]) : __('A repatriation claim has been registered by :community.', ['community' => $community]) }}</p>
<p>{{ __('It concerns :title.', ['title' => \App\Relations\EntityKinds::label($claim->record)]) }} {{ __('Open the claim to review and respond.') }}</p>
@else
<p>{{ __('The claim by :community moved from ":from" to ":to".', ['community' => $community, 'from' => $fromStatus, 'to' => $toStatus]) }}</p>
@endif
<p><a href="{{ route('repatriation.dialogue', $claim) }}">{{ __('Open the claim') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
