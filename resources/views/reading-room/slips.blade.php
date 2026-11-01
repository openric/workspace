{{-- OpenRiC Workspace - call slips to print, one a request (Heratio's paging and call slips; roadmap phase 19b). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><title>{{ __('Call slips') }}</title>
<style>body { font-family: Arial, sans-serif; font-size: 11pt } .slip { border: 1px solid #000; padding: 8px; margin: 0 0 10px; page-break-inside: avoid } dt { font-weight: bold; float: left; width: 9em } dd { margin-left: 9.5em }</style></head>
<body onload="window.print()">@foreach($requests as $r)<div class="slip"><dl>
    <dt>{{ __('Record') }}</dt><dd>{{ \App\Relations\EntityKinds::label($r->record) }}</dd>
    <dt>{{ __('Reference code') }}</dt><dd>{{ $r->record->referenceCode() }}</dd>
    <dt>{{ __('Where it is kept') }}</dt><dd>{{ collect([$r->location_code, $r->shelf_location, $r->box_number ? __('box :n', ['n' => $r->box_number]) : null, $r->folder_number ? __('folder :n', ['n' => $r->folder_number]) : null])->filter()->join(' · ') ?: '-' }}</dd>
    <dt>{{ __('For') }}</dt><dd>{{ $r->booking->researcher->first_name }} {{ $r->booking->researcher->last_name }} ({{ $r->booking->researcher->card_number }}) · {{ $r->booking->room->name }} · {{ $r->booking->booking_date->format('Y-m-d') }} {{ substr($r->booking->start_time, 0, 5) }}</dd>
    <dt>{{ __('Priority') }}</dt><dd>{{ $r->priority->label(['en']) }}</dd>
    @if($r->handling_instructions)<dt>{{ __('Handling') }}</dt><dd>{{ $r->handling_instructions }}</dd>@endif
</dl></div>@endforeach</body></html>
