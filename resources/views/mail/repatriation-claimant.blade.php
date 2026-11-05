{{-- OpenRiC Workspace - RepatriationClaimantMail body (roadmap phase 17d2), after Heratio's emails/repatriation/registered and status. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($institution = \App\Models\Institution::find($claim->institution_id))
<p>{{ __('Hello,') }}</p>
@if($toStatus === null)
<p>{{ __('Thank you. Your repatriation claim has been registered with :institution and will be reviewed by its staff.', ['institution' => $institution->name]) }}</p>
<p>{{ __('It concerns :title.', ['title' => \App\Relations\EntityKinds::label($claim->record)]) }}</p>
@else
<p>{{ __('Your repatriation claim with :institution moved from ":from" to ":to".', ['institution' => $institution->name, 'from' => $fromStatus, 'to' => $toStatus]) }}</p>
@endif
<p>{{ __(\App\Repatriation\Claims::DISCLAIMER) }}</p>
<p>{{ __('Regards,') }}<br>{{ $institution->name }}</p>
