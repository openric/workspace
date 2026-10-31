{{-- OpenRiC Workspace - EmbargoMail body. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@php($lang = [app()->getLocale(), 'en'])
<p>{{ __('Dear :name,', ['name' => $recipient->name]) }}</p>
<p>@switch($event)
    @case('ending'){{ __('The embargo on :record ends on :date. From then it is no longer withheld, unless the embargo is extended.', ['record' => \App\Relations\EntityKinds::label($embargo->record), 'date' => $embargo->ends_on->format('Y-m-d')]) }}@break
    @case('ended'){{ __('The embargo on :record ended on :date. It is no longer withheld.', ['record' => \App\Relations\EntityKinds::label($embargo->record), 'date' => $embargo->ends_on->format('Y-m-d')]) }}@break
    @default{{ __('The embargo on :record was lifted. It is no longer withheld.', ['record' => \App\Relations\EntityKinds::label($embargo->record)]) }}@if($embargo->lift_reason) {{ $embargo->lift_reason }}@endif
@endswitch</p>
<p>{{ __('What it withheld') }}: {{ $embargo->type->label($lang) }}.</p>
<p><a href="{{ route('records.show', $embargo->record) }}">{{ __('Open the record') }}</a></p>
<p>{{ __('Regards,') }}<br>{{ $recipient->institution->name }}</p>
